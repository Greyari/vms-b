<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleCard;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use App\Services\MasterDataClient;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\SimpleExcel\SimpleExcelWriter;

class VisitController extends Controller
{
    public function checkKtp($ktp, MasterDataClient $master)
    {
        $visitor = Visitor::where('id_number', $ktp)->first();

        if ($visitor !== null || ! $master->enabled()) {
            return response()->json($visitor);
        }

        // Not known locally — ask Master Data (crew/employee registry), so
        // returning crew/staff auto-fill on their first VMS visit too.
        try {
            $person = $master->lookupPerson('ktp', $ktp)
                ?? $master->lookupPerson('passport', $ktp);
        } catch (\Throwable $e) {
            report($e);
            $person = null; // auto-fill is convenience — never block the form
        }

        if ($person === null) {
            return response()->json(null);
        }

        // Visitor-shaped payload so the existing form auto-fill just works.
        return response()->json([
            'source' => 'master',
            'id_number' => $ktp,
            'name' => $person['fullName'] ?? null,
            'company' => null,
            'is_blacklisted' => (bool) ($person['isBlacklisted'] ?? false),
            'blacklist_reason' => $person['blacklistReason'] ?? null,
        ]);
    }

    public function searchVisitor(Request $request)
    {
        $q = $request->query('q');
        if (! $q) {
            return response()->json([]);
        }

        // Postgres LIKE is case-sensitive — search through LOWER(...) with the
        // user's wildcards escaped. Full models are returned (nothing hidden),
        // so is_blacklisted / blacklist_reason are included for the caller.
        $escaped = '%'.strtolower(addcslashes($q, '%_\\')).'%';

        $visitors = Visitor::whereRaw('LOWER(id_number) LIKE ?', [$escaped])
            ->orWhereRaw('LOWER(name) LIKE ?', [$escaped])
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
            $visit->time_in = Carbon::parse($visit->time_in)->format('H:i');

            return $visit;
        });

        return response()->json($visits);
    }

    public function history(Request $request)
    {
        $perPage = (int) $request->input('per_page', 50);
        $perPage = max(1, min($perPage, 200));

        $query = Visit::with(['visitor', 'staff', 'visitorCards', 'vehicleCard'])->orderBy('created_at', 'desc');

        if ($request->has('start_date') && $request->has('end_date') &&
            $request->start_date !== '' && $request->end_date !== '') {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        }

        // ->through() applies the H:i transform while preserving paginator meta.
        $paginated = $query->paginate($perPage)->through(function ($visit) {
            $visit->time_in = Carbon::parse($visit->time_in)->format('H:i');

            return $visit;
        });

        return response()->json($paginated);
    }

    public function store(Request $request, MasterDataClient $master)
    {
        $validated = $request->validate([
            'id_type' => 'required|in:ktp,sim,passport',
            'id_number' => 'required|string',
            'name' => 'required|string',
            'company' => 'nullable|string',
            'staff_id' => 'bail|required|uuid|exists:staff,id',
            'card_ids' => 'required|array|min:1',
            'card_ids.*' => 'bail|uuid|exists:visitor_cards,id',
            'vehicle_card_id' => 'bail|nullable|uuid|exists:vehicle_cards,id',
            'purpose' => 'required|string',
            'vehicle_no' => 'nullable|string',
        ]);

        // Read-only blacklist gates run BEFORE the transaction. The Master Data
        // lookup is a 2-5s network call; holding an open DB transaction across
        // it pins a pooled connection and would re-issue the call on every
        // ticket-number retry below. Rejecting here also avoids opening a
        // transaction at all for a blocked visitor. The identity lookup is a
        // read (->first(), never a write), so nothing is persisted if we reject.
        $existingVisitor = Visitor::where('id_type', $validated['id_type'])
            ->where('id_number', $validated['id_number'])
            ->first();

        // Local blacklist. A freshly firstOrCreate'd visitor (inside the
        // transaction) can never be blacklisted — is_blacklisted is not fillable
        // and defaults false — so this pre-check is the only local gate needed.
        if ($existingVisitor && $existingVisitor->is_blacklisted) {
            return response()->json([
                'message' => 'This visitor is blacklisted and cannot be registered for a new visit.',
                'reason' => $existingVisitor->blacklist_reason,
            ], 422);
        }

        // Central blacklist gate (Master Data) — additive to the local check.
        // Fail-open on connectivity unless strict mode is set, so master
        // downtime never halts the gate house.
        if ($master->enabled() && in_array($validated['id_type'], ['ktp', 'passport'], true)) {
            try {
                $person = $master->lookupPerson($validated['id_type'], $validated['id_number']);

                if ($person !== null && ($person['isBlacklisted'] ?? false)) {
                    return response()->json([
                        'message' => 'This visitor is blacklisted in Master Data and cannot be registered.',
                        'reason' => $person['blacklistReason'] ?? null,
                    ], 422);
                }
            } catch (\Throwable $e) {
                report($e);

                if (config('services.master_data.strict')) {
                    return response()->json([
                        'message' => 'Master Data blacklist check is unavailable — registration blocked (strict mode).',
                    ], 503);
                }
            }
        }

        // The write path runs in a transaction. The ticket number is derived
        // under a row lock (B8b); if two concurrent gate-house sessions still
        // race to the same number, the unique(ticket_number) constraint (B10)
        // rejects the loser — we retry with a recomputed number up to 3x. Guard
        // failures throw an HttpResponseException so the transaction rolls back
        // cleanly and the intended 4xx body is returned.
        $maxAttempts = 3;

        for ($attempt = 1; ; $attempt++) {
            try {
                $visit = DB::transaction(function () use ($validated) {
                    $visitor = Visitor::firstOrCreate(
                        [
                            'id_type' => $validated['id_type'],
                            'id_number' => $validated['id_number'],
                        ],
                        [
                            'name' => $validated['name'],
                            'company' => $validated['company'] ?? null,
                        ]
                    );

                    // Block if visitor already has an active visit
                    $hasActiveVisit = Visit::where('visitor_id', $visitor->id)->whereNull('time_out')->exists();
                    if ($hasActiveVisit) {
                        throw new HttpResponseException(response()->json([
                            'message' => 'This visitor is already checked in and currently has an active visit.',
                        ], 422));
                    }

                    // Verify visitor cards are available
                    $visitorCards = VisitorCard::whereIn('id', $validated['card_ids'])->get();
                    if ($visitorCards->contains('status', 'in_use')) {
                        throw new HttpResponseException(
                            response()->json(['message' => 'One or more selected Visitor Cards are in use'], 422)
                        );
                    }

                    // Verify vehicle card is available
                    $vehicleCard = null;
                    if (! empty($validated['vehicle_card_id'])) {
                        $vehicleCard = VehicleCard::find($validated['vehicle_card_id']);
                        if ($vehicleCard && $vehicleCard->status === 'in_use') {
                            throw new HttpResponseException(
                                response()->json(['message' => 'Selected Vehicle Card is in use'], 422)
                            );
                        }
                    }

                    // Auto-Numbering Logic: YY/MM/XXXXX, year-scoped continuation.
                    // lockForUpdate serializes concurrent number derivation.
                    $year = now()->format('y');
                    $month = now()->format('m');
                    $prefix = "{$year}/{$month}/";

                    // Order by the ticket value itself — fixed-width YY/MM/NNNNN
                    // sorts numerically as a string within the year scope — so a
                    // same-second insert still yields the true maximum, not an
                    // ambiguous created_at tie that could recompute a duplicate.
                    $latestVisit = Visit::where('ticket_number', 'like', "{$year}/%")
                        ->orderByDesc('ticket_number')
                        ->lockForUpdate()
                        ->first();

                    if ($latestVisit && $latestVisit->ticket_number) {
                        $parts = explode('/', $latestVisit->ticket_number);
                        $newNumber = (int) end($parts) + 1;
                    } else {
                        $newNumber = 1;
                    }

                    $ticketNumber = $prefix.str_pad((string) $newNumber, 5, '0', STR_PAD_LEFT);

                    $visit = Visit::create([
                        'visitor_id' => $visitor->id,
                        'staff_id' => $validated['staff_id'],
                        'vehicle_card_id' => $vehicleCard ? $vehicleCard->id : null,
                        'purpose' => $validated['purpose'],
                        'date' => now()->toDateString(),
                        'time_in' => now()->toTimeString(),
                        'vehicle_no' => $validated['vehicle_no'] ?? null,
                        'ticket_number' => $ticketNumber,
                    ]);

                    // Sync multi-cards
                    $visit->visitorCards()->sync($validated['card_ids']);
                    VisitorCard::whereIn('id', $validated['card_ids'])->update(['status' => 'in_use']);

                    if ($vehicleCard) {
                        $vehicleCard->update(['status' => 'in_use']);
                    }

                    return $visit;
                });

                $visit->load(['visitor', 'staff', 'visitorCards', 'vehicleCard']);
                $visit->time_in = Carbon::parse($visit->time_in)->format('H:i');

                return response()->json([
                    'message' => 'Visit recorded successfully',
                    'visit' => $visit,
                ], 201);

            } catch (HttpResponseException $e) {
                // A guard rejected the registration — return its response.
                return $e->getResponse();
            } catch (UniqueConstraintViolationException $e) {
                // Ticket-number collision under concurrency — recompute + retry.
                if ($attempt >= $maxAttempts) {
                    return response()->json([
                        'message' => 'Failed to record visit',
                        'error' => 'Could not allocate a unique ticket number, please retry.',
                    ], 500);
                }
                // loop again
            } catch (\Throwable $e) {
                return response()->json(['message' => 'Failed to record visit', 'error' => $e->getMessage()], 500);
            }
        }
    }

    public function print($id)
    {
        $visit = Visit::with(['visitor', 'staff', 'visitorCards', 'vehicleCard'])->findOrFail($id);
        $visit->time_in = Carbon::parse($visit->time_in)->format('H:i');

        return response()->json($visit);
    }

    public function checkout($id)
    {
        try {
            DB::beginTransaction();

            $visit = Visit::findOrFail($id);
            if ($visit->time_out !== null) {
                // Guard fires after DB::beginTransaction() — roll back the open
                // transaction before returning, otherwise it leaks (holding
                // locks in prod; corrupting the next request's transaction
                // state under a shared test connection).
                DB::rollBack();

                return response()->json(['message' => 'Visitor already checked out'], 422);
            }

            $visit->update(['time_out' => now()->toTimeString()]);

            // Revert visitor cards status
            $cardIds = $visit->visitorCards->pluck('id')->toArray();
            if (! empty($cardIds)) {
                VisitorCard::whereIn('id', $cardIds)->update(['status' => 'available']);
            }

            // Revert vehicle card status
            if ($visit->vehicle_card_id) {
                VehicleCard::where('id', $visit->vehicle_card_id)->update(['status' => 'available']);
            }

            DB::commit();

            return response()->json([
                'message' => 'Checkout successful',
                'visit' => $visit,
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
                if (! empty($cardIds)) {
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
                    'ID Type' => $visit->visitor?->id_type ?? '-',
                    'ID Number' => $visit->visitor?->id_number ?? '-',
                    'Visitor Name' => $visit->visitor?->name ?? '-',
                    'Company' => $visit->visitor?->company ?? '-',
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
