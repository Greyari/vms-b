<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleCard;
use App\Services\MasterImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

class VehicleCardController extends Controller
{
    public function downloadTemplate(MasterImportService $importService)
    {
        return $importService->downloadTemplate(MasterImportService::TYPE_VEHICLE_CARD);
    }

    public function importPreview(Request $request, MasterImportService $importService): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,csv|max:5120',
        ]);

        $result = $importService->preview(MasterImportService::TYPE_VEHICLE_CARD, $request->file('file'));

        return response()->json($result);
    }

    public function import(Request $request, MasterImportService $importService): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,csv|max:5120',
            'duplicate_action' => 'nullable|string|in:update,replace',
        ]);

        $duplicateAction = $request->input('duplicate_action', 'update');

        try {
            $result = $importService->import(MasterImportService::TYPE_VEHICLE_CARD, $request->file('file'), $duplicateAction);
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Import failed. ' . $e->getMessage()], 400);
        }
    }

    public function index()
    {
        return response()->json(VehicleCard::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'card_no' => 'required|string|unique:vehicle_cards,card_no',
            'status' => 'required|in:available,in_use',
        ]);

        $card = VehicleCard::create($validated);

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
            'card_no' => 'required|string|unique:vehicle_cards,card_no,'.$card->id,
            'status' => 'required|in:available,in_use',
        ]);

        $card->update($validated);

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
