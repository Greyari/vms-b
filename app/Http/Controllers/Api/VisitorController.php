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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            $query->where(function (Builder $q) use ($escaped, $idNumber): void {
                $q->whereRaw('LOWER(name) LIKE ?', [$escaped])
                    ->orWhereRaw('LOWER(company) LIKE ?', [$escaped]);
                if ($idNumber !== '') {
                    $q->orWhere('id_number', 'like', '%'.addcslashes($idNumber, '%_\\').'%');
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

        $sortWhitelist = ['visitor_no', 'name', 'id_number', 'company', 'created_at', 'visits_count'];
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
        $visitor = Visitor::with('vessel')->withCount('visits')->findOrFail($id);

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
    public function export(Request $request)
    {
        $visitors = $this->filteredVisitors($request, false)->with('vessel');

        return response()->streamDownload(function () use ($visitors) {
            $writer = SimpleExcelWriter::streamDownload('visitors.xlsx');
            $visitors->lazyById(1000)->each(function (Visitor $v) use ($writer): void {
                $writer->addRow([
                    'Visitor No' => $v->visitor_no,
                    'Name' => $v->name,
                    'ID Type' => strtoupper($v->id_type),
                    'ID Number' => $v->id_number,
                    'Visitor Type' => $v->visitor_type?->value,
                    'Gender' => $v->gender,
                    'Birth Date' => $v->birth_date?->format('Y-m-d'),
                    'Birth Place' => $v->birth_place,
                    'Nationality' => $v->nationality,
                    'Occupation' => $v->occupation,
                    'Phone' => $v->phone,
                    'Company' => $v->company,
                    'ID Issued' => $v->id_issued_at?->format('Y-m-d'),
                    'ID Expires' => $v->id_lifetime ? 'Lifetime' : $v->id_expires_at?->format('Y-m-d'),
                    'Vessel' => $v->vessel?->code,
                    'Certificate' => $v->certificate,
                    'Certificate No' => $v->certificate_no,
                    'Seaman Book No' => $v->seaman_book_no,
                    'Seaman Book Expires' => $v->seaman_book_expires_at?->format('Y-m-d'),
                ]);
            });
        }, 'visitors.xlsx');
    }

    public function downloadTemplate()
    {
        return response()->streamDownload(function () {
            $writer = SimpleExcelWriter::streamDownload('visitor_template.xlsx');
            $writer->addRow([
                'ID Type' => 'ktp',
                'ID Number' => '1234567890123456',
                'Name' => 'John Doe',
                'Company' => 'PT Example',
            ]);
        }, 'visitor_template.xlsx');
    }

    /**
     * Normalize a spreadsheet "ID Number" cell to a plain string. Numeric
     * cells arrive as int/float from the reader (e.g. a KTP typed as a number
     * loses leading behaviour / gains scientific notation) — coerce them back
     * to their literal digit string before validation.
     */
    private function normalizeIdNumber(mixed $value): string
    {
        // IDs must arrive as TEXT. A numeric-formatted cell (int or float) may
        // already have been rounded by the spreadsheet before we ever saw it
        // (Excel/openspout round past ~15 significant digits, so a 16-digit KTP
        // typed as a number silently changes value), corrupting the identity
        // and, on a blacklist import, admitting a blacklisted person. Refuse all
        // numerics — the row is skipped and the operator is told to use Text.
        if (is_int($value) || is_float($value)) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * Read up to the configured row cap into memory, aborting (returning a 422
     * response) the moment the file exceeds it so a runaway upload can't be
     * processed inside one long transaction.
     *
     * @return array<int, array<string, mixed>>|JsonResponse
     */
    private function readImportRows(UploadedFile $file)
    {
        $maxRows = (int) config('vms.import_max_rows', 2000);

        $rows = [];
        foreach (SimpleExcelReader::create($file->getRealPath(), $file->getClientOriginalExtension())->getRows() as $row) {
            $rows[] = $row;
            if (count($rows) > $maxRows) {
                return response()->json([
                    'message' => "Import aborted: the file exceeds the maximum of {$maxRows} rows.",
                ], 422);
            }
        }

        return $rows;
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,csv|max:5120']);

        $rows = $this->readImportRows($request->file('file'));
        if ($rows instanceof JsonResponse) {
            return $rows;
        }

        return $this->runImportWithRetry(function () use ($rows): JsonResponse {
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $errors = [];

            foreach ($rows as $index => $row) {
                $line = $index + 2; // account for the header row

                $idNumber = IdentityNumber::normalize($this->normalizeIdNumber($row['ID Number'] ?? null));
                if ($idNumber === '' || ! preg_match('/^[0-9A-Z]+$/', $idNumber)) {
                    $skipped++;
                    $errors[] = "Row {$line}: missing or invalid ID Number (numeric-formatted ID cells are refused — format the ID column as Text and re-upload).";

                    continue;
                }

                $idType = strtolower(trim((string) ($row['ID Type'] ?? '')));
                if (! in_array($idType, ['ktp', 'sim', 'passport'], true)) {
                    $skipped++;
                    $errors[] = "Row {$line}: unknown ID Type '{$idType}' (expected ktp, sim or passport).";

                    continue;
                }

                $name = trim((string) ($row['Name'] ?? ''));
                if ($name === '') {
                    $skipped++;
                    $errors[] = "Row {$line}: missing Name.";

                    continue;
                }

                $company = trim((string) ($row['Company'] ?? ''));

                $visitor = Visitor::updateOrCreate(
                    ['id_type' => $idType, 'id_number' => $idNumber],
                    ['name' => $name, 'company' => $company === '' ? null : $company],
                );

                $visitor->wasRecentlyCreated ? $created++ : $updated++;
            }

            return response()->json([
                'message' => "Import completed. {$created} created, {$updated} updated, {$skipped} skipped.",
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => array_slice($errors, 0, 20),
            ]);
        });
    }

    /**
     * Runs one import attempt through NumberingRetry: inside its own
     * transaction, retried — the whole attempt, transaction included — when it
     * lost a visitor_no allocation race (see Visitor::nextVisitorNo()). Any
     * other unique violation ends the import at once, and every failure is
     * reported to the log. Shared by import() and importBlacklist().
     *
     * $attempt must be self-contained: it (re-)initializes its own created/
     * updated/skipped/errors counters on every call — a retried attempt never
     * inherits state from the one that was just rolled back, so a lost race
     * is never double-counted — and it returns the success JsonResponse
     * itself, which is returned unchanged on a successful attempt.
     *
     * @param  callable(): JsonResponse  $attempt
     */
    private function runImportWithRetry(callable $attempt): JsonResponse
    {
        try {
            return NumberingRetry::run($attempt);
        } catch (UniqueConstraintViolationException $e) {
            // The exception carries SQL with the file's data: the client gets
            // a fixed message and the log keeps the detail. A violation that is
            // still retryable here means every attempt lost the race.
            report($e);

            return response()->json([
                'message' => 'Import failed',
                'error' => UniqueViolation::isRetryable($e)
                    ? 'Could not allocate a unique visitor number, please retry.'
                    : 'The file conflicts with an existing record.',
            ], 500);
        }
    }

    public function exportBlacklist()
    {
        $visitors = Visitor::where('is_blacklisted', true)->orderBy('name')->get();

        return response()->streamDownload(function () use ($visitors) {
            $writer = SimpleExcelWriter::streamDownload('blacklisted_visitors.xlsx');
            foreach ($visitors as $visitor) {
                $writer->addRow([
                    'ID Type' => strtoupper($visitor->id_type),
                    'ID Number' => $visitor->id_number,
                    'Name' => $visitor->name,
                    'Company' => $visitor->company ?? '-',
                    'Reason' => $visitor->blacklist_reason ?? '-',
                ]);
            }
        }, 'blacklisted_visitors.xlsx');
    }

    public function downloadBlacklistTemplate()
    {
        return response()->streamDownload(function () {
            $writer = SimpleExcelWriter::streamDownload('blacklist_template.xlsx');
            $writer->addRow([
                'ID Type' => 'ktp',
                'ID Number' => '1234567890123456',
                'Name' => 'John Doe',
                'Company' => 'PT Example',
                'Reason' => 'Reason for blacklist',
            ]);
        }, 'blacklist_template.xlsx');
    }

    public function importBlacklist(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,csv|max:5120']);

        $rows = $this->readImportRows($request->file('file'));
        if ($rows instanceof JsonResponse) {
            return $rows;
        }

        return $this->runImportWithRetry(function () use ($rows): JsonResponse {
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $errors = [];

            foreach ($rows as $index => $row) {
                $line = $index + 2; // account for the header row

                $idNumber = IdentityNumber::normalize($this->normalizeIdNumber($row['ID Number'] ?? null));
                if ($idNumber === '' || ! preg_match('/^[0-9A-Z]+$/', $idNumber)) {
                    $skipped++;
                    $errors[] = "Row {$line}: missing or invalid ID Number (numeric-formatted ID cells are refused — format the ID column as Text and re-upload).";

                    continue;
                }

                $idType = strtolower(trim((string) ($row['ID Type'] ?? '')));
                if (! in_array($idType, ['ktp', 'sim', 'passport'], true)) {
                    $skipped++;
                    $errors[] = "Row {$line}: unknown ID Type '{$idType}' (expected ktp, sim or passport).";

                    continue;
                }

                $name = trim((string) ($row['Name'] ?? ''));
                if ($name === '') {
                    $skipped++;
                    $errors[] = "Row {$line}: missing Name.";

                    continue;
                }

                $reason = trim((string) ($row['Reason'] ?? ''));
                if ($reason === '') {
                    $skipped++;
                    $errors[] = "Row {$line}: missing Reason.";

                    continue;
                }

                $company = trim((string) ($row['Company'] ?? ''));

                $visitor = Visitor::firstOrCreate(
                    ['id_type' => $idType, 'id_number' => $idNumber],
                    ['name' => $name, 'company' => $company === '' ? null : $company],
                );

                // is_blacklisted / blacklist_reason are guarded — set via forceFill.
                $visitor->forceFill([
                    'is_blacklisted' => true,
                    'blacklist_reason' => $reason,
                ])->save();

                $visitor->wasRecentlyCreated ? $created++ : $updated++;
            }

            return response()->json([
                'message' => "Import completed. {$created} created, {$updated} updated, {$skipped} skipped.",
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => array_slice($errors, 0, 20),
            ]);
        });
    }
}
