<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleCard;
use Illuminate\Http\Request;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

class VehicleCardController extends Controller
{
    public function downloadTemplate()
    {
        return response()->streamDownload(function () {
            $writer = SimpleExcelWriter::streamDownload('vehicle_card_template.xlsx');
            $writer->addRow([
                'card_no' => 'VEHICLE-001',
                'status' => 'available',
            ]);
        }, 'vehicle_card_template.xlsx');
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
                if (! array_key_exists('card_no', $rowProperties) || ! array_key_exists('status', $rowProperties)) {
                    throw new \Exception('Invalid formatting');
                }
                $status = in_array(strtolower((string) $rowProperties['status']), ['available', 'in_use'])
                            ? strtolower((string) $rowProperties['status'])
                            : 'available';

                VehicleCard::updateOrCreate(
                    ['card_no' => $rowProperties['card_no']],
                    ['status' => $status]
                );
                $count++;
            });

            return response()->json(['message' => "Successfully imported {$count} vehicle cards."]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Import failed. Please make sure the format is correct.'], 400);
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
