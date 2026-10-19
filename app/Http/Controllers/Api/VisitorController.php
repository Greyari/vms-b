<?php

namespace App\Http\Controllers\Api;

use App\Enums\VisitorType;
use App\Http\Controllers\Controller;
use App\Http\Requests\VisitorProfileRequest;
use App\Http\Resources\VisitorProfileResource;
use App\Http\Resources\VisitorSummaryResource;
use App\Http\Resources\VisitResource;
use App\Models\Blacklist;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\BlacklistService;
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
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $perPage = (int) $request->input('per_page', 100);
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
            'blacklists',
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

    public function blacklist(Request $request, $id, BlacklistService $blacklistService)
    {
        $validated = $request->validate([
            'reason' => 'required|string',
        ]);

        $visitor = Visitor::findOrFail($id);

        $result = $blacklistService->saveEntry([
            'visitor_id' => $visitor->id,
            'name' => $visitor->name,
            'id_number' => $visitor->id_number,
            'badge_code' => $visitor->badge_code,
            'offences' => $validated['reason'],
            'date' => Carbon::now()->format('Y-m-d'),
            'remark' => $visitor->remark,
            'company' => $visitor->company,
        ], 'replace');

        return response()->json([
            'message' => 'Visitor blacklisted successfully',
            'visitor' => (new VisitorSummaryResource($result['visitor']->refresh()))->resolve(),
        ]);
    }

    // Method to get all blacklisted visitors
    public function blacklisted(Request $request)
    {
        $perPage = (int) $request->input('per_page', 100);
        $perPage = max(1, min($perPage, 100));

        $query = Blacklist::query()
            ->with(['visitor' => fn ($q) => $q->withCount('visits')])
            ->where('is_active', true);

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $escaped = '%'.strtolower(addcslashes($search, '%_\\')).'%';
            $idNumber = IdentityNumber::normalize($search);
            $query->where(function ($q) use ($escaped, $idNumber) {
                $q->whereRaw('LOWER(name) LIKE ?', [$escaped])
                    ->orWhereRaw('LOWER(COALESCE(company, \'\')) LIKE ?', [$escaped])
                    ->orWhereRaw('LOWER(COALESCE(badge_code, \'\')) LIKE ?', [$escaped])
                    ->orWhereRaw('LOWER(COALESCE(offences, \'\')) LIKE ?', [$escaped])
                    ->orWhereRaw('LOWER(COALESCE(remark, \'\')) LIKE ?', [$escaped]);
                if ($idNumber !== '') {
                    $q->orWhere('id_number', 'like', '%'.addcslashes($idNumber, '%_\\').'%');
                }
            });
        }

        $sort = $request->input('sort', 'created_at');
        $dir = strtolower((string) $request->input('dir', 'desc'));
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'desc';
        }

        $sortColumn = match ($sort) {
            'name' => 'name',
            'date', 'blacklisted_at' => 'blacklisted_at',
            'id_number' => 'id_number',
            'badge_code' => 'badge_code',
            'company' => 'company',
            default => 'created_at',
        };

        $paginator = $query->orderBy($sortColumn, $dir)
            ->paginate($perPage)
            ->through(function (Blacklist $bl): array {
                return [
                    'id' => $bl->visitor_id ?: $bl->id,
                    'blacklist_id' => $bl->id,
                    'visitor_id' => $bl->visitor_id,
                    'visitor_no' => $bl->visitor?->visitor_no,
                    'name' => $bl->name,
                    'badge_code' => $bl->badge_code,
                    'id_type' => $bl->visitor?->id_type ?? 'ktp',
                    'id_number' => $bl->id_number,
                    'offences' => $bl->offences,
                    'blacklist_reason' => $bl->offences,
                    'date' => $bl->blacklisted_at?->format('Y-m-d'),
                    'blacklisted_at' => $bl->blacklisted_at?->format('Y-m-d'),
                    'remark' => $bl->remark,
                    'company' => $bl->company,
                    'is_blacklisted' => true,
                    'is_connected' => $bl->visitor_id !== null,
                    'visits_count' => $bl->visitor?->visits_count ?? 0,
                    'visitor' => $bl->visitor ? (new VisitorSummaryResource($bl->visitor))->resolve() : null,
                ];
            });

        return response()->json($paginator);
    }

    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json([]);
        }

        $escaped = '%'.strtolower(addcslashes($q, '%_\\')).'%';
        $idNumber = IdentityNumber::normalize($q);

        $visitors = Visitor::withCount('visits')
            ->where(function ($query) use ($escaped, $idNumber): void {
                $query->whereRaw('LOWER(name) LIKE ?', [$escaped])
                    ->orWhereRaw('LOWER(COALESCE(company, \'\')) LIKE ?', [$escaped]);
                if ($idNumber !== '') {
                    $query->orWhere('id_number', 'like', '%'.addcslashes($idNumber, '%_\\').'%');
                }
            })
            ->limit(15)
            ->get();

        $result = $visitors->map(function (Visitor $v) {
            $latestBadge = $v->badge_code ?: $v->visits()->latest('date')->first()?->visitorCards()->first()?->card_no;

            return [
                'id' => $v->id,
                'name' => $v->name,
                'id_type' => $v->id_type,
                'id_number' => $v->id_number,
                'company' => $v->company,
                'visits_count' => $v->visits_count,
                'is_blacklisted' => (bool) $v->is_blacklisted,
                'blacklist_reason' => $v->blacklist_reason,
                'badge_code' => $latestBadge,
            ];
        });

        return response()->json($result);
    }

    public function unblacklist($id, BlacklistService $blacklistService)
    {
        $blacklistService->revoke((string) $id);

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

    public function storeBlacklist(Request $request, BlacklistService $blacklistService): JsonResponse
    {
        $validated = $request->validate([
            'visitor_id' => 'nullable|uuid|exists:visitors,id',
            'name' => 'required|string|max:255',
            'id_number' => 'nullable|string|max:100',
            'offences' => 'required|string|max:1000',
            'date' => 'required',
            'badge_code' => 'nullable|string|max:255',
            'remark' => 'nullable|string|max:1000',
            'company' => 'nullable|string|max:255',
        ], [
            'name.required' => 'Name of Offender wajib diisi.',
            'offences.required' => 'Offences wajib diisi.',
            'date.required' => 'Date wajib diisi.',
        ]);

        $result = $blacklistService->saveEntry($validated, 'reject');

        return response()->json([
            'message' => 'Data blacklist berhasil disimpan.',
            'blacklist' => $result['blacklist'],
            'visitor' => (new VisitorSummaryResource($result['visitor']->refresh()))->resolve(),
        ], 201);
    }

    public function updateBlacklist(Request $request, $id, BlacklistService $blacklistService): JsonResponse
    {
        $validated = $request->validate([
            'visitor_id' => 'nullable|uuid|exists:visitors,id',
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

        $result = $blacklistService->updateEntry((string) $id, $validated);
        $visitor = $result['visitor'];
        $blacklist = $result['blacklist'];

        return response()->json([
            'message' => 'Data blacklist berhasil diperbarui.',
            'blacklist' => $blacklist,
            'visitor' => $visitor ? (new VisitorSummaryResource($visitor->refresh()))->resolve() : $blacklist,
        ]);
    }

    public function connectBlacklist(Request $request, string $id, BlacklistService $blacklistService): JsonResponse
    {
        $validated = $request->validate([
            'visitor_id' => 'required|uuid|exists:visitors,id',
        ]);

        $result = $blacklistService->connectVisitor($id, $validated['visitor_id']);

        return response()->json([
            'message' => 'Data blacklist berhasil dihubungkan ke data pengunjung.',
            'blacklist' => $result['blacklist'],
            'visitor' => (new VisitorSummaryResource($result['visitor']->refresh()))->resolve(),
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
            'duplicate_action' => 'nullable|string|in:update,replace,skip',
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
