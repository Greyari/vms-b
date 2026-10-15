<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleCard;
use App\Services\MasterExportService;
use App\Services\MasterImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleCardController extends Controller
{
    public function downloadTemplate(MasterImportService $importService)
    {
        return $importService->downloadTemplate(MasterImportService::TYPE_VEHICLE_CARD);
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

        $result = $importService->preview(MasterImportService::TYPE_VEHICLE_CARD, $request->file('file'));

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
            $result = $importService->import(MasterImportService::TYPE_VEHICLE_CARD, $request->file('file'), $duplicateAction);

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Import failed. '.$e->getMessage()], 400);
        }
    }

    private function filteredQuery(Request $request)
    {
        $query = VehicleCard::query();

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $term = '%'.strtolower(addcslashes($search, '%_\\')).'%';
            $query->whereRaw('LOWER(card_no) LIKE ?', [$term]);
        }

        $from = trim((string) ($request->input('from') ?? $request->input('card_no_from') ?? ''));
        if ($from !== '') {
            $query->whereRaw('LOWER(card_no) >= ?', [strtolower($from)]);
        }

        $to = trim((string) ($request->input('to') ?? $request->input('card_no_to') ?? ''));
        if ($to !== '') {
            $query->whereRaw('LOWER(card_no) <= ?', [strtolower($to)]);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return $query;
    }

    public function index(Request $request)
    {
        $query = $this->filteredQuery($request)->orderBy('card_no');

        if ($request->has('page') || $request->has('per_page')) {
            $perPage = (int) $request->input('per_page', 15);
            $perPage = max(1, min($perPage, 100));

            return response()->json($query->paginate($perPage));
        }

        return response()->json($query->get());
    }

    public function export(Request $request, MasterExportService $exportService)
    {
        return $exportService->export('vehicle_cards', $request);
    }

    public function exportCount(Request $request, MasterExportService $exportService): JsonResponse
    {
        return response()->json(['count' => $exportService->count('vehicle_cards', $request)]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'card_no' => 'required|string|max:255|unique:vehicle_cards,card_no',
            'status' => 'nullable|in:available,in_use',
        ]);

        $card = VehicleCard::create([
            'card_no' => $validated['card_no'],
            'status' => $validated['status'] ?? 'available',
        ]);

        return response()->json($card, 201);
    }

    public function show($id)
    {
        $card = VehicleCard::findOrFail($id);

        return response()->json($card);
    }

    public function update(Request $request, $id)
    {
        $card = VehicleCard::findOrFail($id);
        $validated = $request->validate([
            'card_no' => 'required|string|max:255|unique:vehicle_cards,card_no,'.$card->id,
            'status' => 'nullable|in:available,in_use',
        ]);

        $card->update([
            'card_no' => $validated['card_no'],
            'status' => $validated['status'] ?? $card->status,
        ]);

        return response()->json($card);
    }

    public function destroy($id)
    {
        $card = VehicleCard::findOrFail($id);
        $card->delete();

        return response()->json(['message' => 'Vehicle Card deleted successfully']);
    }

    public function trashed()
    {
        return response()->json(VehicleCard::onlyTrashed()->get());
    }

    public function restore($id)
    {
        $card = VehicleCard::onlyTrashed()->findOrFail($id);
        $card->restore();

        return response()->json(['message' => 'Vehicle Card restored successfully']);
    }
}
