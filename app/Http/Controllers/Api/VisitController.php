<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VisitorSummaryResource;
use App\Http\Resources\VisitResource;
use App\Models\VehicleCard;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use App\Support\IdentityNumber;
use App\Support\TicketNumber;
use App\Support\UniqueViolation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\SimpleExcel\SimpleExcelWriter;

class VisitController extends Controller
{
    public function checkKtp($ktp)
    {
        // VMS owns its visitor registry: a visitor is known here or not at all.
        $visitor = Visitor::where('id_number', IdentityNumber::normalize($ktp))->first();

        return response()->json($visitor === null ? null : (new VisitorSummaryResource($visitor))->resolve());
    }

    public function searchVisitor(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json([]);
        }

        // Postgres LIKE is case-sensitive — names go through LOWER(...) with the
        // user's wildcards escaped. id_number is stored normalized (upper case,
        // no spaces or dashes), so the term is normalized the same way before
        // matching. VisitorSummaryResource keeps is_blacklisted and
        // blacklist_reason for the caller and leaves the rest of the profile out.
        $escapedName = '%'.strtolower(addcslashes($q, '%_\\')).'%';
        $idNumber = IdentityNumber::normalize($q);

        $visitors = Visitor::where(function ($query) use ($escapedName, $idNumber): void {
            $query->whereRaw('LOWER(name) LIKE ?', [$escapedName]);
            if ($idNumber !== '') {
                $query->orWhere('id_number', 'like', '%'.addcslashes($idNumber, '%_\\').'%');
            }
        })
            ->limit(10)
            ->get();

        return response()->json(VisitorSummaryResource::collection($visitors)->resolve());
    }

    public function activeVisits()
    {
        $visits = Visit::with(['visitor', 'staff', 'location', 'visitorCards', 'vehicleCard'])
            ->open()
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(VisitResource::collection($visits)->resolve());
    }

    public function history(Request $request)
    {
        $perPage = (int) $request->input('per_page', 50);
        $perPage = max(1, min($perPage, 200));

        $query = Visit::with(['visitor', 'staff', 'location', 'visitorCards', 'vehicleCard'])->orderBy('created_at', 'desc');

        if ($request->has('start_date') && $request->has('end_date') &&
            $request->start_date !== '' && $request->end_date !== '') {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        }

        // ->through() shapes each visit while preserving the paginator meta.
        return response()->json(
            $query->paginate($perPage)->through(fn (Visit $visit): array => (new VisitResource($visit))->resolve())
        );
    }

    public function store(Request $request)
    {
        // One spelling per identity document: validate, look up and store the
        // normalized number (see App\Support\IdentityNumber).
        if (is_string($request->input('id_number'))) {
            $request->merge(['id_number' => IdentityNumber::normalize($request->input('id_number'))]);
        }

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

        // The blacklist gate runs BEFORE the transaction: rejecting here avoids
        // opening one at all for a blocked visitor, and avoids re-running the
        // check on every ticket-number retry below. The identity lookup is a
        // read (->first(), never a write), so nothing is persisted if we reject.
        $existingVisitor = Visitor::where('id_type', $validated['id_type'])
            ->where('id_number', $validated['id_number'])
            ->first();

        // A freshly firstOrCreate'd visitor (inside the
        // transaction) can never be blacklisted — is_blacklisted is not fillable
        // and defaults false — so this pre-check is the only local gate needed.
        if ($existingVisitor && $existingVisitor->is_blacklisted) {
            return response()->json([
                'message' => 'This visitor is blacklisted and cannot be registered for a new visit.',
                'reason' => $existingVisitor->blacklist_reason,
            ], 422);
        }

        // The write path runs in a transaction, which SequenceLock needs: the
        // ticket number and a new visitor's visitor_no are allocated under a
        // PostgreSQL advisory lock held until commit. The unique indexes stay
        // the backstop — a violation UniqueViolation calls retryable gets up to
        // 3 attempts. Guard failures throw an HttpResponseException so the
        // transaction rolls back cleanly and the intended 4xx body is returned.
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
                    $hasActiveVisit = Visit::where('visitor_id', $visitor->id)->open()->exists();
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

                    // YY/MM/NNNNN, counted per month, allocated under SequenceLock.
                    $ticketNumber = TicketNumber::next();

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

                $visit->load(['visitor', 'staff', 'location', 'visitorCards', 'vehicleCard']);

                return response()->json([
                    'message' => 'Visit recorded successfully',
                    'visit' => (new VisitResource($visit))->resolve(),
                ], 201);

            } catch (HttpResponseException $e) {
                // A guard rejected the registration — return its response.
                return $e->getResponse();
            } catch (UniqueConstraintViolationException $e) {
                // Only a lost numbering race is resolved by another attempt
                // (firstOrCreate already settles two registrations of the same
                // person); anything else would fail the same way again. The
                // exception carries SQL with the visitor's data, so the client
                // gets a fixed message and the log keeps the detail.
                if (! UniqueViolation::isRetryable($e)) {
                    report($e);

                    return response()->json([
                        'message' => 'Failed to record visit',
                        'error' => 'The visit conflicts with an existing record.',
                    ], 500);
                }

                if ($attempt >= $maxAttempts) {
                    report($e);

                    return response()->json([
                        'message' => 'Failed to record visit',
                        'error' => 'Could not allocate a unique number, please retry.',
                    ], 500);
                }
                // loop again
            } catch (\Throwable $e) {
                report($e);

                return response()->json([
                    'message' => 'Failed to record visit',
                    'error' => 'An unexpected error stopped the visit from being recorded.',
                ], 500);
            }
        }
    }

    public function print($id)
    {
        $visit = Visit::with(['visitor', 'staff', 'location', 'visitorCards', 'vehicleCard'])->findOrFail($id);

        return response()->json((new VisitResource($visit))->resolve());
    }

    public function checkout($id)
    {
        try {
            DB::beginTransaction();

            $visit = Visit::findOrFail($id);
            if ($visit->checked_out_at !== null) {
                // Guard fires after DB::beginTransaction() — roll back the open
                // transaction before returning, otherwise it leaks (holding
                // locks in prod; corrupting the next request's transaction
                // state under a shared test connection).
                DB::rollBack();

                return response()->json(['message' => 'Visitor already checked out'], 422);
            }

            // checked_out_at carries the date (multi-day visits); time_out stays
            // for readers that still expect a clock time.
            $checkedOutAt = now();
            $visit->update([
                'checked_out_at' => $checkedOutAt,
                'time_out' => $checkedOutAt->toTimeString(),
            ]);

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

            $visit->load(['visitor', 'staff', 'location', 'visitorCards', 'vehicleCard']);

            return response()->json([
                'message' => 'Checkout successful',
                'visit' => (new VisitResource($visit))->resolve(),
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
            if ($visit->checked_out_at === null) {
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
            // The exception can carry SQL with visit data: log it, answer generically.
            report($e);

            return response()->json([
                'message' => 'Failed to delete visit',
                'error' => 'An unexpected error stopped the visit from being deleted.',
            ], 500);
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
