<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

class StaffController extends Controller
{
    public function downloadTemplate()
    {
        return response()->streamDownload(function () {
            $writer = SimpleExcelWriter::streamDownload('staff_template.xlsx');
            $writer->addRow([
                'staff_id' => 'ID-001',
                'name' => 'John Doe',
                'department' => 'IT',
            ]);
        }, 'staff_template.xlsx');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,csv',
        ]);

        try {
            $file = $request->file('file');
            $extension = $file->getClientOriginalExtension();

            $rows = SimpleExcelReader::create($file->getRealPath(), $extension)->getRows();

            $count = 0;
            $rows->each(function (array $rowProperties) use (&$count) {
                if (! isset($rowProperties['staff_id']) || ! isset($rowProperties['name']) || ! isset($rowProperties['department'])) {
                    throw new \Exception('Invalid formatting');
                }

                Staff::updateOrCreate(
                    ['staff_id' => $rowProperties['staff_id']],
                    [
                        'name' => $rowProperties['name'],
                        'department' => $rowProperties['department'],
                    ]
                );
                $count++;
            });

            return response()->json(['message' => "Successfully imported {$count} staffs."]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Import failed. Please make sure the format is correct.'], 400);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->input('per_page', 25), 100));

        return response()->json($this->filteredStaff($request)->orderBy('name')->paginate($perPage));
    }

    public function export(Request $request)
    {
        $staff = $this->filteredStaff($request)->orderBy('name')->get();

        return response()->streamDownload(function () use ($staff) {
            $writer = SimpleExcelWriter::streamDownload('staff.xlsx');
            foreach ($staff as $member) {
                $writer->addRow([
                    'Staff ID' => $member->staff_id,
                    'Name' => $member->name,
                    'Gender' => $member->gender,
                    'Birth Date' => $member->birth_date?->format('Y-m-d'),
                    'Company' => $member->company,
                    'Department' => $member->department,
                    'Occupation' => $member->occupation,
                    'Address' => $member->address,
                    'Status' => $member->is_active ? 'Active' : 'Inactive',
                ]);
            }
        }, 'staff.xlsx');
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->profileRules() + [
            'staff_id' => 'required|string|max:255|unique:staff,staff_id',
        ]);

        $staff = Staff::create($validated);

        return response()->json($staff, 201);
    }

    public function show($id)
    {
        $staff = Staff::findOrFail($id);

        return response()->json($staff);
    }

    public function update(Request $request, $id)
    {
        $staff = Staff::findOrFail($id);
        $validated = $request->validate($this->profileRules() + [
            'staff_id' => 'required|string|max:255|unique:staff,staff_id,'.$staff->id,
        ]);

        $staff->update($validated);

        return response()->json($staff);
    }

    public function destroy($id)
    {
        $staff = Staff::findOrFail($id);
        $staff->delete();

        return response()->json(['message' => 'Staff deleted successfully']);
    }

    public function trashed()
    {
        return response()->json(Staff::onlyTrashed()->get());
    }

    public function restore($id)
    {
        $staff = Staff::onlyTrashed()->findOrFail($id);
        $staff->restore();

        return response()->json(['message' => 'Staff restored successfully']);
    }

    /**
     * Staff matching the master screen's filters: a case-insensitive search
     * on name or staff number, the company, and the active flag. Postgres LIKE
     * is case-sensitive, so both sides are lower-cased, wildcards escaped.
     */
    private function filteredStaff(Request $request): Builder
    {
        $request->validate(['is_active' => ['nullable', Rule::in(['0', '1', 'true', 'false'])]]);

        $query = Staff::query();

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $term = '%'.strtolower(addcslashes($search, '%_\\')).'%';
            $query->where(fn (Builder $q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(staff_id) LIKE ?', [$term]));
        }

        $company = trim((string) $request->input('company', ''));
        if ($company !== '') {
            $query->whereRaw('LOWER(company) LIKE ?', ['%'.strtolower(addcslashes($company, '%_\\')).'%']);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return $query;
    }

    /**
     * The profile fields the create and the edit form both send. Only
     * staff_id differs between them, by its unique rule.
     *
     * @return array<string, mixed>
     */
    private function profileRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'department' => 'nullable|string|max:255',
            'gender' => ['nullable', Rule::in(['M', 'F'])],
            'birth_date' => 'nullable|date|before_or_equal:today',
            'company' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'occupation' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
