<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VisitorCard;
use Illuminate\Http\Request;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

class CardController extends Controller
{
    public function downloadTemplate()
    {
        return response()->streamDownload(function () {
            $writer = SimpleExcelWriter::streamDownload('card_template.xlsx');
            $writer->addRow([
                'card_no' => 'CARD-001',
                'status' => 'available', // available or in_use
            ]);
        }, 'card_template.xlsx');
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

                VisitorCard::updateOrCreate(
                    ['card_no' => $rowProperties['card_no']],
                    ['status' => $status]
                );
                $count++;
            });

            return response()->json(['message' => "Successfully imported {$count} cards."]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Import failed. Please make sure the format is correct.'], 400);
        }
    }

    public function index()
    {
        return response()->json(VisitorCard::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'card_no' => 'required|string|unique:visitor_cards,card_no',
            'status' => 'required|in:available,in_use',
        ]);

        $card = VisitorCard::create($validated);

        return response()->json($card, 201);
    }

    public function show($id)
    {
        $card = VisitorCard::findOrFail($id);

        return response()->json($card);
    }

    public function update(Request $request, $id)
    {
        $card = VisitorCard::findOrFail($id);
        $validated = $request->validate([
            'card_no' => 'required|string|unique:visitor_cards,card_no,'.$card->id,
            'status' => 'required|in:available,in_use',
        ]);

        $card->update($validated);

        return response()->json($card);
    }

    public function destroy($id)
    {
        $card = VisitorCard::findOrFail($id);
        $card->delete();

        return response()->json(['message' => 'Card deleted successfully']);
    }

    public function trashed()
    {
        return response()->json(VisitorCard::onlyTrashed()->get());
    }

    public function restore($id)
    {
        $card = VisitorCard::onlyTrashed()->findOrFail($id);
        $card->restore();

        return response()->json(['message' => 'Card restored successfully']);
    }
}
