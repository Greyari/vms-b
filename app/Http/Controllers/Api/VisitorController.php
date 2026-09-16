<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VisitorSummaryResource;
use App\Models\Visitor;
use App\Support\IdentityNumber;
use App\Support\UniqueViolation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

class VisitorController extends Controller
{
    /**
     * Shared builder for the paginated visitor lists (active + blacklisted),
     * applying case-insensitive search + a whitelisted sort. Postgres LIKE is
     * case-sensitive, so search goes through LOWER(...) with user wildcards
     * escaped.
     */
    private function paginatedVisitors(Request $request, bool $blacklisted): LengthAwarePaginator
    {
        $perPage = (int) $request->input('per_page', 15);
        $perPage = max(1, min($perPage, 100));

        $query = Visitor::withCount('visits')
            ->where('is_blacklisted', $blacklisted);

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

        $sortWhitelist = ['name', 'id_number', 'company', 'created_at', 'visits_count'];
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

    public function update(Request $request, $id)
    {
        $visitor = Visitor::findOrFail($id);

        if (is_string($request->input('id_number'))) {
            $request->merge(['id_number' => IdentityNumber::normalize($request->input('id_number'))]);
        }

        $validated = $request->validate([
            'id_type' => 'required|in:ktp,sim,passport',
            // M3 — identity is (id_type, id_number); enforce the composite
            // uniqueness in validation so a clash returns 422, not a DB 500.
            'id_number' => [
                'required', 'string',
                Rule::unique('visitors', 'id_number')
                    ->where(fn ($q) => $q->where('id_type', $request->input('id_type')))
                    ->ignore($id),
            ],
            'name' => 'required|string',
            'company' => 'nullable|string',
        ]);

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

    public function export()
    {
        $visitors = Visitor::where('is_blacklisted', false)->orderBy('name')->get();

        return response()->streamDownload(function () use ($visitors) {
            $writer = SimpleExcelWriter::streamDownload('visitors.xlsx');
            foreach ($visitors as $v) {
                $writer->addRow([
                    'ID Type' => strtoupper($v->id_type),
                    'ID Number' => $v->id_number,
                    'Name' => $v->name,
                    'Company' => $v->company ?? '-',
                ]);
            }
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
     * Runs one import attempt inside its own transaction, retrying — the
     * whole attempt, transaction included — up to 3 times in total when it
     * throws a UniqueConstraintViolationException that
     * UniqueViolation::isRetryable() accepts: the attempt lost a visitor_no
     * allocation race (see Visitor::nextVisitorNo()). The transaction has
     * already rolled back everything the attempt wrote, so a retry recomputes
     * cleanly. Any other unique violation ends the import at once, and every
     * failure is reported to the log. Shared by import() and
     * importBlacklist() so the retry loop exists in one place.
     *
     * $attempt must be self-contained: it (re-)initializes its own created/
     * updated/skipped/errors counters on every call — a retried attempt never
     * inherits state from the one that was just rolled back, so a lost race
     * is never double-counted — and it returns the success JsonResponse
     * itself, which is returned unchanged by DB::transaction() on a
     * successful attempt.
     *
     * @param  callable(): JsonResponse  $attempt
     */
    private function runImportWithRetry(callable $attempt): JsonResponse
    {
        $maxAttempts = 3;

        for ($i = 1; ; $i++) {
            try {
                return DB::transaction($attempt);
            } catch (UniqueConstraintViolationException $e) {
                // The exception carries SQL with the file's data: the client
                // gets a fixed message and the log keeps the detail.
                if (! UniqueViolation::isRetryable($e)) {
                    report($e);

                    return response()->json([
                        'message' => 'Import failed',
                        'error' => 'The file conflicts with an existing record.',
                    ], 500);
                }

                if ($i >= $maxAttempts) {
                    report($e);

                    return response()->json([
                        'message' => 'Import failed',
                        'error' => 'Could not allocate a unique visitor number, please retry.',
                    ], 500);
                }
                // loop again
            }
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
