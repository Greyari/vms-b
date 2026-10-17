<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Services\MasterExportService;
use App\Services\MasterImportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    public function downloadTemplate(MasterImportService $importService)
    {
        return $importService->downloadTemplate(MasterImportService::TYPE_STAFF);
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

        $result = $importService->preview(MasterImportService::TYPE_STAFF, $request->file('file'));

        return response()->json($result);
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
            $result = $importService->import(MasterImportService::TYPE_STAFF, $request->file('file'), $duplicateAction);

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Import failed. '.$e->getMessage()], 400);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->input('per_page', 25), 100));

        return response()->json($this->filteredStaff($request)->orderBy('name')->paginate($perPage));
    }

    public function export(Request $request, MasterExportService $exportService)
    {
        return $exportService->export('staffs', $request);
    }

    public function exportCount(Request $request, MasterExportService $exportService): JsonResponse
    {
        return response()->json(['count' => $exportService->count('staffs', $request)]);
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
                ->orWhereRaw('LOWER(staff_id) LIKE ?', [$term])
                ->orWhereRaw('LOWER(company) LIKE ?', [$term])
                ->orWhereRaw('LOWER(address) LIKE ?', [$term]));
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
            'gender' => ['nullable', Rule::in(['M', 'F', 'Male', 'Female', 'male', 'female', 'L', 'P', 'laki-laki', 'perempuan', 'pria', 'wanita'])],
            'birth_date' => 'nullable|date|before_or_equal:today',
            'company' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'occupation' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
