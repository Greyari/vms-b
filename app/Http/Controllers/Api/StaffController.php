<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use Illuminate\Http\Request;
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

    public function index()
    {
        return response()->json(Staff::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'staff_id' => 'required|string|unique:staff,staff_id',
            'name' => 'required|string|max:255',
            'department' => 'required|string|max:255',
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
        $validated = $request->validate([
            'staff_id' => 'required|string|unique:staff,staff_id,'.$staff->id,
            'name' => 'required|string|max:255',
            'department' => 'required|string|max:255',
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
}
