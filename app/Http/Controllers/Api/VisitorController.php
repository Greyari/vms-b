<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Visitor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
            $query->where(function (Builder $q) use ($escaped): void {
                $q->whereRaw('LOWER(id_number) LIKE ?', [$escaped])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$escaped])
                    ->orWhereRaw('LOWER(company) LIKE ?', [$escaped]);
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

        return $query->orderBy($sort, $dir)->paginate($perPage);
    }

    public function index(Request $request)
    {
        return response()->json($this->paginatedVisitors($request, false));
    }

    public function update(Request $request, $id)
    {
        $visitor = Visitor::findOrFail($id);

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
        // blacklist first (which is an auditable action).
        if ($visitor->is_blacklisted
            && ($validated['id_type'] !== $visitor->id_type
                || $validated['id_number'] !== $visitor->id_number)) {
            return response()->json([
                'message' => 'This visitor is blacklisted — remove them from the blacklist before changing their identity.',
            ], 422);
        }

        $visitor->update($validated);

        return response()->json([
            'message' => 'Visitor updated successfully',
            'visitor' => $visitor,
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
            'visitor' => $visitor,
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

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        DB::transaction(function () use ($rows, &$created, &$updated, &$skipped, &$errors): void {
            foreach ($rows as $index => $row) {
                $line = $index + 2; // account for the header row

                $idNumber = $this->normalizeIdNumber($row['ID Number'] ?? null);
                if ($idNumber === '' || ! preg_match('/^[0-9A-Za-z-]+$/', $idNumber)) {
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
        });

        return response()->json([
            'message' => "Import completed. {$created} created, {$updated} updated, {$skipped} skipped.",
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 20),
        ]);
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

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        DB::transaction(function () use ($rows, &$created, &$updated, &$skipped, &$errors): void {
            foreach ($rows as $index => $row) {
                $line = $index + 2; // account for the header row

                $idNumber = $this->normalizeIdNumber($row['ID Number'] ?? null);
                if ($idNumber === '' || ! preg_match('/^[0-9A-Za-z-]+$/', $idNumber)) {
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
        });

        return response()->json([
            'message' => "Import completed. {$created} created, {$updated} updated, {$skipped} skipped.",
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 20),
        ]);
    }
}
