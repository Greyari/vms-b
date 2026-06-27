<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Visitor;
use Illuminate\Http\Request;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

class VisitorController extends Controller
{
    public function index()
    {
        $visitors = Visitor::withCount('visits')
            ->where('is_blacklisted', false)
            ->orderBy('name')
            ->get();

        return response()->json($visitors);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'ktp_number' => 'required|string',
            'name' => 'required|string',
            'company' => 'nullable|string',
            'rfid_uid' => 'nullable|string',
        ]);

        $visitor = Visitor::findOrFail($id);
        $visitor->update($validated);

        return response()->json([
            'message' => 'Visitor updated successfully',
            'visitor' => $visitor
        ]);
    }

    public function blacklist(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string',
        ]);

        $visitor = Visitor::findOrFail($id);
        $visitor->update([
            'is_blacklisted' => true,
            'blacklist_reason' => $validated['reason'],
        ]);

        return response()->json([
            'message' => 'Visitor blacklisted successfully',
            'visitor' => $visitor
        ]);
    }

    // Method to get all blacklisted visitors
    public function blacklisted()
    {
        $visitors = Visitor::withCount('visits')
            ->where('is_blacklisted', true)
            ->orderBy('name')
            ->get();

        return response()->json($visitors);
    }

    public function unblacklist($id)
    {
        $visitor = Visitor::findOrFail($id);
        $visitor->update([
            'is_blacklisted' => false,
            'blacklist_reason' => null,
        ]);

        return response()->json(['message' => 'Visitor removed from blacklist']);
    }

    public function destroy($id)
    {
        try {
            $visitor = Visitor::findOrFail($id);
            $visitor->delete();

            return response()->json(['message' => 'Visitor deleted successfully']);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'message' => 'Cannot delete this visitor because they have existing visit history.'
            ], 422);
        }
    }

    public function export()
    {
        $visitors = Visitor::where('is_blacklisted', false)->orderBy('name')->get();

        return response()->streamDownload(function () use ($visitors) {
            $writer = SimpleExcelWriter::streamDownload('visitors.xlsx');
            foreach ($visitors as $v) {
                $writer->addRow([
                    'KTP Number' => $v->ktp_number,
                    'Name'       => $v->name,
                    'Company'    => $v->company ?? '-',
                    'RFID UID'   => $v->rfid_uid ?? '-',
                ]);
            }
        }, 'visitors.xlsx');
    }

    public function downloadTemplate()
    {
        return response()->streamDownload(function () {
            $writer = SimpleExcelWriter::streamDownload('visitor_template.xlsx');
            $writer->addRow([
                'KTP Number' => '1234567890123456',
                'Name'       => 'John Doe',
                'Company'    => 'PT Example',
                'RFID UID'   => '',
            ]);
        }, 'visitor_template.xlsx');
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,csv']);

        $file = $request->file('file');
        $rows = SimpleExcelReader::create($file->getRealPath(), $file->getClientOriginalExtension())->getRows();

        $imported = 0; $skipped = 0;
        foreach ($rows as $row) {
            $ktp = $row['KTP Number'] ?? null;
            if (!$ktp) { $skipped++; continue; }

            Visitor::updateOrCreate(
                ['ktp_number' => $ktp],
                [
                    'name'     => $row['Name'] ?? 'Unknown',
                    'company'  => $row['Company'] ?? null,
                    'rfid_uid' => $row['RFID UID'] ?? null,
                ]
            );
            $imported++;
        }

        return response()->json([
            'message' => "Import completed. {$imported} visitor(s) imported, {$skipped} row(s) skipped."
        ]);
    }

    public function exportBlacklist()
    {
        $visitors = Visitor::where('is_blacklisted', true)->orderBy('name')->get();

        return response()->streamDownload(function () use ($visitors) {
            $writer = SimpleExcelWriter::streamDownload('blacklisted_visitors.xlsx');

            foreach ($visitors as $visitor) {
                $writer->addRow([
                    'KTP Number' => $visitor->ktp_number,
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
                'KTP Number' => '1234567890123456',
                'Name' => 'John Doe',
                'Company' => 'PT Example',
                'Reason' => 'Reason for blacklist',
            ]);
        }, 'blacklist_template.xlsx');
    }

    public function importBlacklist(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,csv',
        ]);

        $file = $request->file('file');
        $extension = $file->getClientOriginalExtension();
        $rows = SimpleExcelReader::create($file->getRealPath(), $extension)->getRows();

        $imported = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $ktp = $row['KTP Number'] ?? null;
            $reason = $row['Reason'] ?? null;

            if (!$ktp || !$reason) {
                $skipped++;
                continue;
            }

            $visitor = Visitor::firstOrCreate(
                ['ktp_number' => $ktp],
                [
                    'name' => $row['Name'] ?? 'Unknown',
                    'company' => $row['Company'] ?? null,
                ]
            );

            $visitor->update([
                'is_blacklisted' => true,
                'blacklist_reason' => $reason,
            ]);

            $imported++;
        }

        return response()->json([
            'message' => "Import completed. {$imported} visitor(s) blacklisted, {$skipped} row(s) skipped due to missing data."
        ]);
    }


}
