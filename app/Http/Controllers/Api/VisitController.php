<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use App\Models\VehicleCard;
use Illuminate\Support\Facades\DB;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Barryvdh\DomPDF\Facade\Pdf;

class VisitController extends Controller
{
    public function checkKtp($ktp)
    {
        $visitor = Visitor::where('ktp_number', $ktp)->first();
        return response()->json($visitor); // Returns null if not found
    }

    public function checkRfid($uid)
    {
        $visitor = Visitor::where('rfid_uid', $uid)->first();
        return response()->json($visitor);
    }

    public function searchVisitor(Request $request)
    {
        $q = $request->query('q');
        if (!$q) {
            return response()->json([]);
        }

        $visitors = Visitor::where('ktp_number', 'like', "%{$q}%")
            ->orWhere('name', 'like', "%{$q}%")
            ->limit(10)
            ->get();

        return response()->json($visitors);
    }

    public function activeVisits()
    {
        $visits = Visit::with(['visitor', 'staff', 'visitorCards', 'vehicleCard'])
            ->whereNull('time_out')
            ->orderBy('created_at', 'desc')
            ->get();

        $visits->transform(function ($visit) {
            $visit->time_in = \Carbon\Carbon::parse($visit->time_in)->format('H:i');
            return $visit;
        });

        return response()->json($visits);
    }

    public function history(Request $request)
    {
        $query = Visit::with(['visitor', 'staff', 'visitorCards', 'vehicleCard'])->orderBy('created_at', 'desc');

        if ($request->has('start_date') && $request->has('end_date') &&
            $request->start_date !== '' && $request->end_date !== '') {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'ktp_number' => 'required|string',
            'name' => 'required|string',
            'company' => 'nullable|string',
            'rfid_uid' => 'nullable|string',
            'staff_id' => 'required|exists:staff,id',
            'card_ids' => 'required|array|min:1',
            'card_ids.*' => 'exists:visitor_cards,id',
            'vehicle_card_id' => 'nullable|exists:vehicle_cards,id',
            'purpose' => 'required|string',
            'vehicle_no' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            $visitorData = [
                'name' => $validated['name'],
                'company' => $validated['company']
            ];

            if (!empty($validated['rfid_uid'])) {
                $visitorData['rfid_uid'] = $validated['rfid_uid'];
            }

            $visitor = Visitor::firstOrCreate(
                ['ktp_number' => $validated['ktp_number']],
                $visitorData
            );

            // Update RFID if missing but provided now
            if (!empty($validated['rfid_uid']) && $visitor->rfid_uid !== $validated['rfid_uid']) {
                $visitor->update(['rfid_uid' => $validated['rfid_uid']]);
            }

            // Block if visitor is blacklisted
            if ($visitor->is_blacklisted) {
                DB::rollBack();
                return response()->json([
                    'message' => 'This visitor is blacklisted and cannot be registered for a new visit.',
                    'reason' => $visitor->blacklist_reason,
                ], 422);
            }

            // Block if visitor already has an active visit (not checked out yet)
            $hasActiveVisit = Visit::where('visitor_id', $visitor->id)->whereNull('time_out')->exists();
            if ($hasActiveVisit) {
                DB::rollBack();
                return response()->json([
                    'message' => 'This visitor is already checked in and currently has an active visit.',
                ], 422);
            }

            // Verify visitor cards are available
            $visitorCards = VisitorCard::whereIn('id', $validated['card_ids'])->get();
            if ($visitorCards->contains('status', 'in_use')) {
                return response()->json(['message' => 'One or more selected Visitor Cards are in use'], 422);
            }

            // Verify vehicle card is available
            $vehicleCard = null;
            if (!empty($validated['vehicle_card_id'])) {
                $vehicleCard = VehicleCard::find($validated['vehicle_card_id']);
                if ($vehicleCard && $vehicleCard->status === 'in_use') {
                    return response()->json(['message' => 'Selected Vehicle Card is in use'], 422);
                }
            }

            // Auto-Numbering Logic: YY/MM/XXXXX
            $year = now()->format('y');
            $month = now()->format('m');
            $prefix = "{$year}/{$month}/";

            $latestVisit = Visit::where('ticket_number', 'like', "{$year}/%")->orderBy('id', 'desc')->first();

            if ($latestVisit && $latestVisit->ticket_number) {
                $parts = explode('/', $latestVisit->ticket_number);
                $lastNumber = (int) end($parts);
                $newNumber = $lastNumber + 1;
            } else {
                $newNumber = 1;
            }

            $ticketNumber = $prefix . str_pad($newNumber, 5, '0', STR_PAD_LEFT);

            $visit = Visit::create([
                'visitor_id' => $visitor->id,
                'staff_id' => $validated['staff_id'],
                'vehicle_card_id' => $vehicleCard ? $vehicleCard->id : null,
                'purpose' => $validated['purpose'],
                'date' => now()->toDateString(),
                'time_in' => now()->toTimeString(),
                'vehicle_no' => $validated['vehicle_no'],
                'ticket_number' => $ticketNumber,
            ]);

            // Sync multi-cards
            $visit->visitorCards()->sync($validated['card_ids']);
            VisitorCard::whereIn('id', $validated['card_ids'])->update(['status' => 'in_use']);

            if ($vehicleCard) {
                $vehicleCard->update(['status' => 'in_use']);
            }

            DB::commit();

            $visit->load(['visitor', 'staff', 'visitorCards', 'vehicleCard']);
            $visit->time_in = \Carbon\Carbon::parse($visit->time_in)->format('H:i');

            return response()->json([
                'message' => 'Visit recorded successfully',
                'visit' => $visit
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to record visit', 'error' => $e->getMessage()], 500);
        }
    }

    public function print($id)
    {
        $visit = Visit::with(['visitor', 'staff', 'visitorCards', 'vehicleCard'])->findOrFail($id);
        $visit->time_in = \Carbon\Carbon::parse($visit->time_in)->format('H:i');
        return response()->json($visit);
    }

    public function checkout($id)
    {
        try {
            DB::beginTransaction();

            $visit = Visit::findOrFail($id);
            if ($visit->time_out !== null) {
                 return response()->json(['message' => 'Visitor already checked out'], 422);
            }

            $visit->update(['time_out' => now()->toTimeString()]);

            // Revert visitor cards status
            $cardIds = $visit->visitorCards->pluck('id')->toArray();
            if (!empty($cardIds)) {
                VisitorCard::whereIn('id', $cardIds)->update(['status' => 'available']);
            }

            // Revert vehicle card status
            if ($visit->vehicle_card_id) {
                VehicleCard::where('id', $visit->vehicle_card_id)->update(['status' => 'available']);
            }

            DB::commit();

            return response()->json([
                'message' => 'Checkout successful',
                'visit' => $visit
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Checkout failed'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            DB::beginTransaction();

            $visit = Visit::findOrFail($id);

            // Revert card status if not yet checked out
            if ($visit->time_out === null) {
                $cardIds = $visit->visitorCards->pluck('id')->toArray();
                if (!empty($cardIds)) {
                    VisitorCard::whereIn('id', $cardIds)->update(['status' => 'available']);
                }
                if ($visit->vehicle_card_id) {
                    VehicleCard::where('id', $visit->vehicle_card_id)->update(['status' => 'available']);
                }
            }

            $visit->delete();

            DB::commit();

            return response()->json(['message' => 'Visit deleted successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to delete visit', 'error' => $e->getMessage()], 500);
        }
    }

    public function export(Request $request)
    {
        $query = Visit::with(['visitor', 'staff', 'visitorCards', 'vehicleCard']);

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        }

        $visits = $query->get();
        // Since Spatie SimpleExcel download stream is actually meant to be returned directly as a streamed response
        return response()->streamDownload(function () use ($visits) {
            $writer = SimpleExcelWriter::streamDownload('visits.xlsx');

            foreach ($visits as $visit) {
                $writer->addRow([
                    'Date' => $visit->date,
                    'Time In' => $visit->time_in,
                    'Time Out' => $visit->time_out ?? '-',
                    'KTP Number' => $visit->visitor->ktp_number,
                    'Visitor Name' => $visit->visitor->name,
                    'Company' => $visit->visitor->company ?? '-',
                    'Purpose' => $visit->purpose,
                    'Host Staff' => $visit->staff?->name ?? 'Unknown',
                    'Visitor Cards' => $visit->visitorCards->pluck('card_no')->join(', ') ?: '-',
                    'Vehicle Card' => $visit->vehicleCard?->card_no ?? '-',
                    'Vehicle No' => $visit->vehicle_no ?? '-',
                ]);
            }
        }, 'visits.xlsx');
    }

    public function exportPdf(Request $request)
    {
        $query = Visit::with(['visitor', 'staff', 'visitorCards', 'vehicleCard'])->orderBy('date', 'desc')->orderBy('time_in', 'desc');

        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        if ($startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]);
        }

        $visits = $query->get();

        $pdf = Pdf::loadView('pdf.visitor-report', compact('visits', 'startDate', 'endDate'))
                  ->setPaper('a4', 'landscape');

        return $pdf->download('visitor-report.pdf');
    }
}
