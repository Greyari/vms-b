<?php

namespace App\Http\Controllers\Api;

use App\Enums\LocationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\LocationRequest;
use App\Models\Location;
use App\Services\MasterExportService;
use App\Services\MasterImportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    public function export(Request $request, MasterExportService $exportService)
    {
        return $exportService->export('locations', $request);
    }

    public function exportCount(Request $request, MasterExportService $exportService): JsonResponse
    {
        return response()->json(['count' => $exportService->count('locations', $request)]);
    }

    public function downloadTemplate(MasterImportService $importService)
    {
        return $importService->downloadTemplate(MasterImportService::TYPE_LOCATION);
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

        $result = $importService->preview(MasterImportService::TYPE_LOCATION, $request->file('file'));

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
            $result = $importService->import(MasterImportService::TYPE_LOCATION, $request->file('file'), $duplicateAction);

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Import failed. '.$e->getMessage()], 400);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', Rule::enum(LocationType::class)],
            'is_active' => ['nullable', Rule::in(['0', '1', 'true', 'false'])],
        ]);

        $perPage = max(1, min((int) $request->input('per_page', 25), 2000));
        $query = Location::query();

        // Postgres LIKE is case-sensitive: compare lower-cased, wildcards escaped.
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $term = '%'.strtolower(addcslashes($search, '%_\\')).'%';
            $query->where(fn (Builder $q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(code) LIKE ?', [$term])
                ->orWhereRaw('LOWER(COALESCE(vessel_type, \'\')) LIKE ?', [$term])
                ->orWhereRaw('LOWER(COALESCE(call_sign, \'\')) LIKE ?', [$term])
                ->orWhereRaw('LOWER(COALESCE(flag, \'\')) LIKE ?', [$term])
                ->orWhereRaw('LOWER(COALESCE(captain, \'\')) LIKE ?', [$term])
                ->orWhereRaw('LOWER(COALESCE(pic, \'\')) LIKE ?', [$term]));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return response()->json($query->orderBy('name')->paginate($perPage));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(Location::findOrFail($id));
    }

    public function store(LocationRequest $request): JsonResponse
    {
        $location = Location::create($request->validated());

        return response()->json($location->refresh(), 201);
    }

    public function update(LocationRequest $request, string $id): JsonResponse
    {
        $location = Location::findOrFail($id);
        $validated = $request->validated();

        // A place keeps the code it was created with (see LocationRequest).
        if ($validated['type'] === LocationType::Location->value) {
            unset($validated['code']);
        }

        $location->update($validated);

        return response()->json($location->refresh());
    }

    public function destroy(string $id): JsonResponse
    {
        Location::findOrFail($id)->delete();

        return response()->json(['message' => 'Location deleted successfully']);
    }
}
