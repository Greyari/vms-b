<?php

namespace App\Http\Controllers\Api;

use App\Enums\VisitorType;
use App\Http\Controllers\Controller;
use App\Http\Requests\VisitorProfileRequest;
use App\Http\Resources\VisitorProfileResource;
use App\Http\Resources\VisitorSummaryResource;
use App\Http\Resources\VisitResource;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\MasterExportService;
use App\Services\MasterImportService;
use App\Support\IdentityNumber;
use App\Support\NumberingRetry;
use App\Support\UniqueViolation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

class VisitorController extends Controller
{
    /**
     * Visitors matching the list screen's filters: a case-insensitive search
     * on name, company or ID number, and the visitor type. Postgres LIKE is
     * case-sensitive, so search goes through LOWER(...) with user wildcards
     * escaped. Shared by the lists and the export.
     */
    private function filteredVisitors(Request $request, bool $blacklisted): Builder
    {
        $request->validate(['visitor_type' => ['nullable', Rule::enum(VisitorType::class)]]);

        $query = Visitor::query()->where('is_blacklisted', $blacklisted);

        if ($request->filled('visitor_type')) {
            $query->where('visitor_type', $request->input('visitor_type'));
        }

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $escaped = '%'.strtolower(addcslashes($search, '%_\\')).'%';
            $idNumber = IdentityNumber::normalize($search);
            $query->where(function (Builder $q) use ($escaped, $idNumber, $blacklisted): void {
                $q->whereRaw('LOWER(name) LIKE ?', [$escaped])
                    ->orWhereRaw('LOWER(company) LIKE ?', [$escaped]);
                if ($idNumber !== '') {
                    $q->orWhere('id_number', 'like', '%'.addcslashes($idNumber, '%_\\').'%');
                }
                if ($blacklisted) {
                    $q->orWhereRaw('LOWER(COALESCE(badge_code, \'\')) LIKE ?', [$escaped])
                        ->orWhereRaw('LOWER(COALESCE(offences, \'\')) LIKE ?', [$escaped])
                        ->orWhereRaw('LOWER(COALESCE(blacklist_reason, \'\')) LIKE ?', [$escaped])
                        ->orWhereRaw('LOWER(COALESCE(remark, \'\')) LIKE ?', [$escaped]);
                }
            });
        }

        return $query;
    }

    /**
     * The paginated visitor lists (active and blacklisted) with a whitelisted
     * sort.
     */
    private function paginatedVisitors(Request $request, bool $blacklisted): LengthAwarePaginator
    {
        $perPage = (int) $request->input('per_page', 15);
        $perPage = max(1, min($perPage, 100));

        $query = $this->filteredVisitors($request, $blacklisted)->withCount('visits');

        $sortWhitelist = ['visitor_no', 'name', 'id_number', 'company', 'created_at', 'visits_count', 'badge_code', 'blacklisted_at'];
        $sort = $request->input('sort', 'name');
        if (! in_array($sort, $sortWhitelist, true)) {
            $sort = 'name';
        }
        $dir = strtolower((string) $request->input('dir', 'asc'));
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'asc';
        }

        return $query->orderBy($sort, $dir)
            ->paginate($perPage)
            ->through(fn (Visitor $visitor): array => (new VisitorSummaryResource($visitor))->resolve());
    }

    public function index(Request $request)
    {
        return response()->json($this->paginatedVisitors($request, false));
    }

    /**
     * The profile screen: the whole profile, and a summary of the visits —
     * how many, the first and the last date, and the open one if any.
     */
    public function show(string $id): JsonResponse
    {
        $visitor = Visitor::with([
            'vessel',
            'visits' => fn ($q) => $q->with(['staff', 'location', 'visitorCards', 'vehicleCard'])
                ->orderByDesc('date')
                ->orderByDesc('time_in')
                ->limit(50),
        ])->withCount('visits')->findOrFail($id);

        return response()->json([
            'visitor' => (new VisitorProfileResource($visitor))->resolve(),
            'summary' => [
                'visits_count' => $visitor->visits_count,
                'first_visit_date' => $visitor->visits()->min('date'),
                'last_visit_date' => $visitor->visits()->max('date'),
                'open_visit_id' => $visitor->visits()->open()->latest('date')->value('id'),
            ],
        ]);
    }

    /**
     * A visitor's visits one month at a time, as VMS-old's registration screen
     * showed them: every month that has visits (newest first, with a count),
     * and the visits of the requested month, by default the newest one.
     */
    public function visits(Request $request, string $id): JsonResponse
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        $visitor = Visitor::findOrFail($id);

        $months = $visitor->visits()
            ->orderByDesc('date')
            ->pluck('date')
            ->countBy(fn (string $date): string => substr($date, 0, 7))
            ->map(fn (int $count, string $month): array => ['month' => $month, 'visits' => $count])
            ->values();

        $month = $request->input('month') ?? ($months->isEmpty() ? null : $months->first()['month']);

        $visits = collect();
        if ($month !== null) {
            // A real month end: PostgreSQL refuses a date such as 2026-02-31.
            $start = Carbon::createFromFormat('!Y-m', $month);
            $visits = $visitor->visits()
                ->with(['staff', 'location', 'visitorCards', 'vehicleCard'])
                ->whereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
                ->orderByDesc('date')
                ->orderByDesc('time_in')
                ->get()
                ->map(fn (Visit $visit): array => (new VisitResource($visit))->resolve());
        }

        return response()->json([
            'months' => $months,
            'month' => $month,
            'visits' => $visits->values(),
        ]);
    }

    /**
     * Registers a visitor with a full profile — the quick registration at
     * check-in and the visitor master both use it. The next visitor_no is
     * allocated inside the transaction NumberingRetry opens.
     */
    public function store(VisitorProfileRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $visitor = NumberingRetry::run(fn (): Visitor => Visitor::create($validated));
        } catch (UniqueConstraintViolationException $e) {
            // Another request registered the same person between validation
            // and insert: the same answer as the unique rule gives.
            if (UniqueViolation::violates($e, 'visitors', ['id_type', 'id_number'])) {
                throw ValidationException::withMessages([
                    'id_number' => VisitorProfileRequest::DUPLICATE_IDENTITY,
                ]);
            }

            // The exception carries SQL with the visitor's data: the client
            // gets a fixed message and the log keeps the detail.
            report($e);

            return response()->json([
                'message' => 'Failed to register visitor',
                'error' => UniqueViolation::isRetryable($e)
                    ? 'Could not allocate a unique visitor number, please retry.'
                    : 'The visitor conflicts with an existing record.',
            ], 500);
        }

        return response()->json([
            'message' => 'Visitor registered successfully',
            'visitor' => (new VisitorSummaryResource($visitor->refresh()))->resolve(),
        ], 201);
    }

    public function update(VisitorProfileRequest $request, $id)
    {
        $visitor = Visitor::findOrFail($id);
        $validated = $request->validated();

        // M4 — block blacklist-evasion by renumbering: a blacklisted visitor
        // may not have their identity changed. They must be removed from the
        // blacklist first (which is an auditable action). The stored number is
        // normalized before comparing, so a row written before normalization
        // does not read as a change when the same number is typed again.
        if ($visitor->is_blacklisted
            && ($validated['id_type'] !== $visitor->id_type
                || $validated['id_number'] !== IdentityNumber::normalize($visitor->id_number))) {
            return response()->json([
                'message' => 'This visitor is blacklisted — remove them from the blacklist before changing their identity.',
            ], 422);
        }

        $visitor->update($validated);

        return response()->json([
            'message' => 'Visitor updated successfully',
            'visitor' => (new VisitorSummaryResource($visitor))->resolve(),
        ]);
    }

    public function blacklist(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string',
        ]);

        $visitor = Visitor::findOrFail($id);
        // is_blacklisted / blacklist_reason are guarded (not $fillable) so a
        // mass-assigned visitor payload can never flip blacklist state — set
        // them explicitly here.
        $visitor->forceFill([
            'is_blacklisted' => true,
            'blacklist_reason' => $validated['reason'],
        ])->save();

        return response()->json([
            'message' => 'Visitor blacklisted successfully',
            'visitor' => (new VisitorSummaryResource($visitor))->resolve(),
        ]);
    }

    // Method to get all blacklisted visitors
    public function blacklisted(Request $request)
    {
        return response()->json($this->paginatedVisitors($request, true));
    }

    public function unblacklist($id)
    {
        $visitor = Visitor::findOrFail($id);
        $visitor->forceFill([
            'is_blacklisted' => false,
            'blacklist_reason' => null,
        ])->save();

        return response()->json(['message' => 'Visitor removed from blacklist']);
    }

    public function destroy($id)
    {
        $visitor = Visitor::findOrFail($id);

        // visits.visitor_id is ON DELETE CASCADE, so deleting a visitor with
        // history would silently wipe the visit audit trail and strand their
        // assigned cards as `in_use`. Refuse explicitly — the old QueryException
        // guard never fired because the cascade means no FK error is thrown.
        if ($visitor->visits()->exists()) {
            return response()->json([
                'message' => 'Cannot delete this visitor because they have visit history. Blacklist them instead.',
            ], 422);
        }

        // vessel_arrival_crew.visitor_id is ON DELETE RESTRICT, so a crew member
        // listed on a vessel arrival cannot be hard-deleted; without this check
        // the delete fails with a QueryException and the client gets a 500.
        if ($visitor->vesselArrivals()->exists()) {
            return response()->json([
                'message' => 'Cannot delete this visitor because they are listed as crew on a vessel arrival. Blacklist them instead.',
            ], 422);
        }

        $visitor->delete();

        return response()->json(['message' => 'Visitor deleted successfully']);
    }

    /**
     * The visitor master as Excel, with the profile columns and the list's
     * filters. Rows stream in chunks: the imported registry holds some
     * 150,000 visitors, too many to load at once.
     */
    public function export(Request $request, MasterExportService $exportService)
    {
        return $exportService->export('visitors', $request);
    }

    public function exportCount(Request $request, MasterExportService $exportService): JsonResponse
    {
        return response()->json(['count' => $exportService->count('visitors', $request)]);
    }

    public function downloadTemplate(MasterImportService $importService)
    {
        return $importService->downloadTemplate(MasterImportService::TYPE_VISITOR);
    }

    public function importPreview(Request $request, MasterImportService $importService): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:10240',
                function ($attribute, $value, $fail) {
                    $ext = strtolower($value->getClientOriginalExtension());
                    if (! in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
                        $fail('The file field must be a file of type: xlsx, xls, csv.');
                    }
                },
            ],
        ]);

        $result = $importService->preview(MasterImportService::TYPE_VISITOR, $request->file('file'));

        return response()->json($result);
    }

    private function normalizeIdNumber(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return '';
        }
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    public function import(Request $request, MasterImportService $importService): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:10240',
                function ($attribute, $value, $fail) {
                    $ext = strtolower($value->getClientOriginalExtension());
                    if (! in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
                        $fail('The file field must be a file of type: xlsx, xls, csv.');
                    }
                },
            ],
            'duplicate_action' => 'nullable|string|in:update,replace',
        ]);

        $duplicateAction = $request->input('duplicate_action', 'update');

        try {
            $result = $importService->import(MasterImportService::TYPE_VISITOR, $request->file('file'), $duplicateAction);

            return response()->json($result);
        } catch (UniqueConstraintViolationException $e) {
            report($e);

            return response()->json([
                'message' => 'Import failed',
                'error' => UniqueViolation::isRetryable($e)
                    ? 'Could not allocate a unique visitor number, please retry.'
                    : 'The file conflicts with an existing record.',
            ], 500);
        } catch (ValidationException $e) {
            $msg = $e->validator ? $e->validator->errors()->first('file') : $e->getMessage();

            return response()->json([
                'message' => $msg ?: $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'exceeds the maximum')) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json(['message' => 'Import failed. '.$e->getMessage()], 400);
        }
    }

    public function storeBlacklist(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'id_number' => 'nullable|string|max:100',
            'offences' => 'required|string|max:1000',
            'date' => 'required',
            'badge_code' => 'nullable|string|max:255',
            'remark' => 'nullable|string|max:1000',
            'company' => 'nullable|string|max:255',
        ], [
            'name.required' => 'NAME OF OFFENDER wajib diisi.',
            'offences.required' => 'OFFENCES wajib diisi.',
            'date.required' => 'DATE wajib diisi.',
        ]);

        $name = trim($validated['name']);
        $rawKtp = ! empty($validated['id_number']) ? trim($validated['id_number']) : '';
        $badgeCode = ! empty($validated['badge_code']) ? trim($validated['badge_code']) : null;
        $date = MasterImportService::parseDate($validated['date']) ?? Carbon::now()->format('Y-m-d');

        $parsedIdentity = MasterImportService::parseIdentity($rawKtp, $badgeCode, $name);
        $idType = $parsedIdentity['id_type'];
        $idNumber = $parsedIdentity['id_number'];
        if ($badgeCode === null && ! empty($parsedIdentity['badge_code'])) {
            $badgeCode = $parsedIdentity['badge_code'];
        }

        $existing = null;
        if ($parsedIdentity['has_real_id']) {
            $existing = Visitor::where('id_number', $idNumber)->first();
        }
        if (! $existing && $badgeCode !== null) {
            $existing = Visitor::where('badge_code', $badgeCode)->first();
        }
        if (! $existing && ! $parsedIdentity['has_real_id']) {
            $existing = Visitor::where('id_number', $idNumber)
                ->orWhereRaw('LOWER(name) = ?', [strtolower($name)])
                ->first();
        }

        $data = [
            'name' => $name,
            'id_type' => $idType,
            'id_number' => $idNumber,
            'badge_code' => $badgeCode,
            'offences' => trim($validated['offences']),
            'blacklist_reason' => trim($validated['offences']),
            'blacklisted_at' => $date,
            'remark' => ! empty($validated['remark']) ? trim($validated['remark']) : null,
            'company' => ! empty($validated['company']) ? trim($validated['company']) : null,
            'is_blacklisted' => true,
        ];

        if ($existing) {
            $existing->forceFill($data)->save();
            $visitor = $existing;
        } else {
            $visitor = DB::transaction(function () use ($data) {
                $newVisitor = new Visitor;
                $newVisitor->forceFill($data);
                $newVisitor->save();

                return $newVisitor;
            });
        }

        return response()->json([
            'message' => 'Data blacklist berhasil disimpan.',
            'visitor' => (new VisitorSummaryResource($visitor->refresh()))->resolve(),
        ], 201);
    }

    public function updateBlacklist(Request $request, $id): JsonResponse
    {
        $visitor = Visitor::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'id_number' => 'nullable|string|max:100',
            'offences' => 'required|string|max:1000',
            'date' => 'required',
            'badge_code' => 'nullable|string|max:255',
            'remark' => 'nullable|string|max:1000',
            'company' => 'nullable|string|max:255',
        ], [
            'name.required' => 'NAME OF OFFENDER wajib diisi.',
            'offences.required' => 'OFFENCES wajib diisi.',
            'date.required' => 'DATE wajib diisi.',
        ]);

        $name = trim($validated['name']);
        $rawKtp = ! empty($validated['id_number']) ? trim($validated['id_number']) : '';
        $badgeCode = ! empty($validated['badge_code']) ? trim($validated['badge_code']) : null;
        $date = MasterImportService::parseDate($validated['date']) ?? Carbon::now()->format('Y-m-d');

        $parsedIdentity = MasterImportService::parseIdentity($rawKtp, $badgeCode, $name);
        $idType = $parsedIdentity['id_type'];
        $idNumber = $parsedIdentity['id_number'];
        if ($badgeCode === null && ! empty($parsedIdentity['badge_code'])) {
            $badgeCode = $parsedIdentity['badge_code'];
        }

        $visitor->forceFill([
            'name' => $name,
            'id_type' => $idType,
            'id_number' => $idNumber,
            'badge_code' => $badgeCode,
            'offences' => trim($validated['offences']),
            'blacklist_reason' => trim($validated['offences']),
            'blacklisted_at' => $date,
            'remark' => ! empty($validated['remark']) ? trim($validated['remark']) : null,
            'company' => ! empty($validated['company']) ? trim($validated['company']) : null,
            'is_blacklisted' => true,
        ])->save();

        return response()->json([
            'message' => 'Data blacklist berhasil diperbarui.',
            'visitor' => (new VisitorSummaryResource($visitor->refresh()))->resolve(),
        ]);
    }

    public function exportBlacklist(Request $request, MasterExportService $exportService)
    {
        return $exportService->export('blacklist', $request);
    }

    public function exportBlacklistCount(Request $request, MasterExportService $exportService): JsonResponse
    {
        return response()->json([
            'count' => $exportService->count('blacklist', $request),
        ]);
    }

    public function downloadBlacklistTemplate(MasterImportService $importService)
    {
        return $importService->downloadTemplate(MasterImportService::TYPE_BLACKLIST);
    }

    public function importBlacklistPreview(Request $request, MasterImportService $importService): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:10240',
                function ($attribute, $value, $fail) {
                    $ext = strtolower($value->getClientOriginalExtension());
                    if (! in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
                        $fail('The file field must be a file of type: xlsx, xls, csv.');
                    }
                },
            ],
        ]);

        $result = $importService->preview(MasterImportService::TYPE_BLACKLIST, $request->file('file'));

        return response()->json($result);
    }

    public function importBlacklist(Request $request, MasterImportService $importService): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:10240',
                function ($attribute, $value, $fail) {
                    $ext = strtolower($value->getClientOriginalExtension());
                    if (! in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
                        $fail('The file field must be a file of type: xlsx, xls, csv.');
                    }
                },
            ],
            'duplicate_action' => 'nullable|string|in:update,replace',
        ]);

        $duplicateAction = $request->input('duplicate_action', 'update');

        try {
            $result = $importService->import(MasterImportService::TYPE_BLACKLIST, $request->file('file'), $duplicateAction);

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Import failed. '.$e->getMessage()], 400);
        }
    }
}
