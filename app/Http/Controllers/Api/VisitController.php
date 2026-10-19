<?php

namespace App\Http\Controllers\Api;

use App\Enums\LocationType;
use App\Enums\PreRegistrationStatus;
use App\Enums\VisitorType;
use App\Http\Controllers\Controller;
use App\Http\Resources\VisitorSummaryResource;
use App\Http\Resources\VisitResource;
use App\Models\Blacklist;
use App\Models\GlobalBlacklist;
use App\Models\Location;
use App\Models\PreRegistration;
use App\Models\Staff;
use App\Models\VehicleCard;
use App\Models\VesselArrival;
use App\Models\VesselArrivalCrewLog;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use App\Services\ActivityLogger;
use App\Support\GlobalBlacklistCheck;
use App\Support\IdentityNumber;
use App\Support\NumberingRetry;
use App\Support\SequenceLock;
use App\Support\TicketNumber;
use App\Support\UniqueViolation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\SimpleExcel\SimpleExcelWriter;

class VisitController extends Controller
{
    public function checkKtp($ktp)
    {
        $normalizedKtp = IdentityNumber::normalize($ktp);
        // VMS owns its visitor registry: a visitor is known here or not at all.
        $visitor = Visitor::where('id_number', $normalizedKtp)->first();

        if ($visitor) {
            $activeBlacklist = Blacklist::where('is_active', true)
                ->where(function ($q) use ($visitor, $normalizedKtp) {
                    $q->where('visitor_id', $visitor->id)
                        ->orWhere('id_number', $normalizedKtp);
                })
                ->latest('blacklisted_at')
                ->first();

            if ($activeBlacklist && ! $visitor->is_blacklisted) {
                $visitor->is_blacklisted = true;
                $visitor->blacklist_reason = $activeBlacklist->offences;
            }
        }

        // Hybrid spec §6.2: the officer sees global entries for this number as soon as it is typed,
        // whatever document type they were listed under (SEC-4); the SPA compares the type.
        $global = GlobalBlacklistCheck::activeEntries((string) $ktp)
            ->map(fn (GlobalBlacklist $entry): array => GlobalBlacklistCheck::describe($entry))
            ->values()
            ->all();

        return response()->json([
            'data' => $visitor === null ? null : (new VisitorSummaryResource($visitor))->resolve(request()),
            'global_blacklist' => $global,
        ]);
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

    public function activeVisits(Request $request)
    {
        $query = Visit::with(['visitor', 'staff', 'location', 'visitorCards', 'vehicleCard'])
            ->open()
            ->orderBy('created_at', 'desc');

        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->query('date_to'));
        }

        if ($request->filled('ticket_number')) {
            $term = trim((string) $request->query('ticket_number'));
            $escaped = '%'.strtolower(addcslashes($term, '%_\\')).'%';
            $query->where(function ($q) use ($escaped) {
                $q->whereRaw('LOWER(ticket_number) LIKE ?', [$escaped])
                    ->orWhereRaw('LOWER(COALESCE(legacy_registration_no, \'\')) LIKE ?', [$escaped]);
            });
        }

        if ($request->filled('visitor_name')) {
            $term = trim((string) $request->query('visitor_name'));
            $escaped = '%'.strtolower(addcslashes($term, '%_\\')).'%';
            $query->whereHas('visitor', function ($q) use ($escaped) {
                $q->whereRaw('LOWER(name) LIKE ?', [$escaped]);
            });
        }

        if ($request->filled('card_no')) {
            $cardTerm = trim((string) $request->query('card_no'));
            $escaped = '%'.strtolower(addcslashes($cardTerm, '%_\\')).'%';
            $query->where(function ($q) use ($escaped) {
                $q->whereHas('visitorCards', function ($vcQuery) use ($escaped) {
                    $vcQuery->whereRaw('LOWER(card_no) LIKE ?', [$escaped]);
                })->orWhereHas('vehicleCard', function ($vcQuery) use ($escaped) {
                    $vcQuery->whereRaw('LOWER(card_no) LIKE ?', [$escaped]);
                });
            });
        }

        if ($request->filled('company')) {
            $term = trim((string) $request->query('company'));
            $escaped = '%'.strtolower(addcslashes($term, '%_\\')).'%';
            $query->where(function ($q) use ($escaped) {
                $q->whereRaw('LOWER(COALESCE(visitor_company, \'\')) LIKE ?', [$escaped])
                    ->orWhereHas('visitor', function ($vQuery) use ($escaped) {
                        $vQuery->whereRaw('LOWER(COALESCE(company, \'\')) LIKE ?', [$escaped]);
                    });
            });
        }

        if ($request->has('per_page')) {
            $perPage = (int) $request->query('per_page', 100);
            $perPage = max(1, min($perPage, 1000));

            return response()->json(
                $query->paginate($perPage)->through(fn (Visit $visit): array => (new VisitResource($visit))->resolve())
            );
        }

        $visits = $query->get();

        return response()->json(VisitResource::collection($visits)->resolve());
    }

    public function history(Request $request)
    {
        $perPage = (int) $request->input('per_page', 100);
        $perPage = max(1, min($perPage, 1000));

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
            'location_id' => 'bail|required|uuid|exists:locations,id',
            'staff_id' => 'bail|required_without:host_name|nullable|uuid|exists:staff,id',
            'host_name' => 'bail|required_without:staff_id|nullable|string|max:255',
            'visitor_type' => ['sometimes', 'nullable', Rule::enum(VisitorType::class)],
            'gender' => ['nullable', Rule::in(['M', 'F'])],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'birth_place' => ['nullable', 'string', 'max:255'],
            'nationality' => ['nullable', 'string', 'max:255'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'id_issued_at' => ['nullable', 'date'],
            'id_expires_at' => ['nullable', 'date'],
            'id_lifetime' => ['sometimes', 'nullable', 'boolean'],
            'vessel_id' => [
                'nullable', 'uuid',
                Rule::exists('locations', 'id')
                    ->whereNot('type', LocationType::Location->value)
                    ->whereNull('deleted_at'),
            ],
            'certificate' => ['nullable', 'string', 'max:255'],
            'certificate_no' => ['nullable', 'string', 'max:255'],
            'seaman_book_no' => ['nullable', 'string', 'max:255'],
            'seaman_book_expires_at' => ['nullable', 'date'],
            'close_open_visits' => 'sometimes|nullable|boolean',
            'card_ids' => 'nullable|array',
            'card_ids.*' => 'bail|uuid|exists:visitor_cards,id',
            'vehicle_card_id' => 'bail|nullable|uuid|exists:vehicle_cards,id',
            'purpose' => 'required|string',
            'vehicle_no' => 'nullable|string',
            // Hybrid spec §6.2: the scanned QR token, and a supervisor's override of a global entry (SEC-7).
            'pre_registration_token' => ['sometimes', 'nullable', 'string', 'max:30'],
            'override_global_blacklist' => ['sometimes', 'boolean'],
            'override_reason' => ['required_if_accepted:override_global_blacklist', 'nullable', 'string', 'min:10', 'max:500'],
        ]);

        // The blacklist gate runs BEFORE the transaction: rejecting here avoids
        // opening one at all for a blocked visitor, and avoids re-running the
        // check on every ticket-number retry below. The identity lookup is a
        // read (->first(), never a write), so nothing is persisted if we reject.
        $existingVisitor = Visitor::where('id_type', $validated['id_type'])
            ->where('id_number', $validated['id_number'])
            ->first();

        $activeBlacklist = Blacklist::where('is_active', true)
            ->where(function ($q) use ($existingVisitor, $validated) {
                $q->where('id_number', $validated['id_number']);
                if ($existingVisitor) {
                    $q->orWhere('visitor_id', $existingVisitor->id);
                }
            })
            ->latest('blacklisted_at')
            ->first();

        if (($existingVisitor && $existingVisitor->is_blacklisted) || $activeBlacklist !== null) {
            return response()->json([
                'message' => 'This visitor is blacklisted and cannot be registered for a new visit.',
                'reason' => $activeBlacklist?->offences ?? $existingVisitor?->blacklist_reason ?? $existingVisitor?->offences,
            ], 422);
        }

        // The scanned pre-registration must still be usable (hybrid spec §6.2).
        $preRegistration = null;
        if (filled($validated['pre_registration_token'] ?? null)) {
            $preRegistration = PreRegistration::firstWhere('token', PreRegistration::normalizeToken($validated['pre_registration_token']));
            $refusal = match ($preRegistration?->effectiveStatus()) {
                null => 'This pre-registration is not known at this gate yet.',
                PreRegistrationStatus::CheckedIn => 'This pre-registration has already been used for a check-in.',
                PreRegistrationStatus::Expired => 'This pre-registration has expired.',
                default => null,
            };
            if ($refusal !== null) {
                return response()->json(['message' => $refusal], 422);
            }
        }

        // Global blacklist, checked before any visitor is created, new visitors included (BE-6). The same
        // number under the same document type blocks unless a supervisor overrides it with a reason
        // (SEC-7); under another type it only warns (SEC-4).
        $globalEntries = GlobalBlacklistCheck::activeEntries($validated['id_number']);
        $blocking = GlobalBlacklistCheck::blocking($globalEntries, $validated['id_type']);
        $canOverride = (bool) $request->user()?->can('manage_blacklist');
        if ($blocking !== null && ! $request->boolean('override_global_blacklist')) {
            return response()->json([
                'message' => 'This visitor is on the global blacklist.',
                'global_blacklist' => GlobalBlacklistCheck::describe($blocking),
                'can_override' => $canOverride,
            ], 422);
        }
        if ($blocking !== null && ! $canOverride) {
            return response()->json(['message' => 'Only a supervisor can let a visitor on the global blacklist through.'], 403);
        }
        $warnings = GlobalBlacklistCheck::warnings($globalEntries, $validated['id_type']);

        $location = Location::find($validated['location_id']);
        $locationName = $location?->name;

        $hostName = null;
        if (! empty($validated['staff_id'])) {
            $staff = Staff::find($validated['staff_id']);
            $hostName = $staff?->name;
        } else {
            $hostName = $validated['host_name'] ?? null;
        }

        $closeOpenVisits = $request->boolean('close_open_visits');

        $profileKeys = [
            'name', 'company', 'visitor_type', 'gender', 'birth_date', 'birth_place',
            'nationality', 'occupation', 'phone', 'id_issued_at', 'id_expires_at',
            'id_lifetime', 'vessel_id', 'certificate', 'certificate_no',
            'seaman_book_no', 'seaman_book_expires_at',
        ];
        $newVisitorAttributes = array_intersect_key($validated, array_flip($profileKeys));

        // The write path runs inside NumberingRetry::run(), which opens each
        // attempt in its own transaction. The ticket number and a new visitor's
        // visitor_no are allocated under a PostgreSQL advisory lock held until
        // commit. The unique indexes stay the backstop — a retryable violation
        // gets up to NumberingRetry::MAX_ATTEMPTS attempts.
        try {
            $visit = NumberingRetry::run(function () use ($request, $validated, $location, $locationName, $hostName, $newVisitorAttributes, $closeOpenVisits, $preRegistration) {
                $visitor = Visitor::firstOrCreate(
                    [
                        'id_type' => $validated['id_type'],
                        'id_number' => $validated['id_number'],
                    ],
                    $newVisitorAttributes
                );

                $visitorCompany = $validated['company'] ?? $visitor->company;
                $visitorUpdates = [];
                if (isset($validated['company']) && $validated['company'] !== '' && $validated['company'] !== $visitor->company) {
                    $visitorUpdates['company'] = $validated['company'];
                }
                foreach (['visitor_type', 'occupation', 'vessel_id', 'certificate', 'certificate_no', 'seaman_book_no', 'seaman_book_expires_at'] as $crewField) {
                    if (! empty($validated[$crewField]) && $validated[$crewField] !== $visitor->{$crewField}) {
                        $visitorUpdates[$crewField] = $validated[$crewField];
                    }
                }
                if (! empty($visitorUpdates)) {
                    $visitor->update($visitorUpdates);
                }

                // Every check-in takes the ticket lock before the guards
                // below, so a concurrent check-in of the same visitor or
                // card has committed by the time they read open visits and
                // card status. Taken after firstOrCreate, the lock order
                // stays visitor_no then ticket_number. The clock is read
                // after any wait for the lock.
                SequenceLock::acquire('ticket_number');
                $now = now();

                // Check if visitor already has an active visit
                $activeVisit = Visit::where('visitor_id', $visitor->id)->open()->first();
                if ($activeVisit) {
                    if (! $closeOpenVisits) {
                        throw new HttpResponseException(response()->json([
                            'message' => 'This visitor is already checked in and currently has an active visit.',
                            'open_visit_id' => $activeVisit->id,
                        ], 422));
                    }

                    if (! $request->user()?->can('checkout_visits')) {
                        throw new HttpResponseException(response()->json([
                            'message' => 'You do not have permission to check out active visits.',
                        ], 403));
                    }

                    // Close all open visits for this visitor and release cards
                    $openVisits = Visit::where('visitor_id', $visitor->id)->open()->get();
                    foreach ($openVisits as $openVisit) {
                        $openVisit->update([
                            'checked_out_at' => $now,
                            'time_out' => $now->toTimeString(),
                        ]);

                        $cardIds = $openVisit->visitorCards()->pluck('visitor_cards.id');
                        if ($cardIds->isNotEmpty()) {
                            VisitorCard::whereIn('id', $cardIds)->update(['status' => 'available']);
                        }

                        if ($openVisit->vehicle_card_id) {
                            VehicleCard::where('id', $openVisit->vehicle_card_id)->update(['status' => 'available']);
                        }
                    }
                }

                $cardIds = array_values(array_filter($validated['card_ids'] ?? []));

                // Verify visitor cards are available
                if (! empty($cardIds)) {
                    $visitorCards = VisitorCard::whereIn('id', $cardIds)->get();
                    if ($visitorCards->contains('status', 'in_use')) {
                        throw new HttpResponseException(
                            response()->json(['message' => 'One or more selected Visitor Cards are in use'], 422)
                        );
                    }
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

                // YY/MM/NNNNN, counted per month. The lock is already held,
                // and taking it again inside next() is a no-op.
                $ticketNumber = TicketNumber::next($now);

                $visit = Visit::create([
                    'visitor_id' => $visitor->id,
                    'staff_id' => $validated['staff_id'] ?? null,
                    'location_id' => $validated['location_id'],
                    'location_name' => $locationName,
                    'host_name' => $hostName,
                    'visitor_company' => $visitorCompany,
                    'vehicle_card_id' => $vehicleCard ? $vehicleCard->id : null,
                    'purpose' => $validated['purpose'],
                    'date' => $now->toDateString(),
                    'time_in' => $now->toTimeString(),
                    'vehicle_no' => $validated['vehicle_no'] ?? null,
                    'ticket_number' => $ticketNumber,
                ]);

                // Sync multi-cards
                if (! empty($cardIds)) {
                    $visit->visitorCards()->sync($cardIds);
                    VisitorCard::whereIn('id', $cardIds)->update(['status' => 'in_use']);
                }

                if ($vehicleCard) {
                    $vehicleCard->update(['status' => 'in_use']);
                }

                // The scanned pre-registration, or else one booked for today under the same number (a
                // walk-in who had pre-registered, PE-4). Read fresh on every attempt: a retry follows a
                // rollback, and an instance changed by the failed attempt would not save again.
                $linked = $preRegistration !== null
                    ? PreRegistration::find($preRegistration->id)
                    : PreRegistration::pendingFor($validated['id_number'], $now->toDateString());
                $linked?->markCheckedIn($visit);

                // If visitor is a Crew (kru kapal), automatically assign & check-in on the vessel's active arrival
                $visitorTypeVal = $visitor->visitor_type instanceof VisitorType
                    ? $visitor->visitor_type->value
                    : (string) $visitor->visitor_type;
                $isCrew = $visitorTypeVal === VisitorType::Crew->value
                    || ($validated['visitor_type'] ?? null) === VisitorType::Crew->value
                    || ($validated['visitor_type'] ?? null) === VisitorType::Crew;

                if ($isCrew) {
                    $targetVessel = ! empty($validated['vessel_id'])
                        ? Location::find($validated['vessel_id'])
                        : (($location && $location->type !== LocationType::Location)
                            ? $location
                            : (! empty($visitor->vessel_id) ? Location::find($visitor->vessel_id) : null));

                    if ($targetVessel && $targetVessel->type !== LocationType::Location) {
                        if ($visitor->vessel_id !== $targetVessel->id) {
                            $visitor->update(['vessel_id' => $targetVessel->id]);
                        }

                        $activeArrival = VesselArrival::where('location_id', $targetVessel->id)
                            ->whereNull('sign_out_date')
                            ->orderBy('sign_in_date', 'desc')
                            ->orderBy('created_at', 'desc')
                            ->first();

                        if (! $activeArrival) {
                            $activeArrival = VesselArrival::create([
                                'location_id' => $targetVessel->id,
                                'vessel_name' => $targetVessel->name,
                                'sign_in_date' => $now->toDateString(),
                                'captain' => $targetVessel->captain,
                            ]);
                        }

                        $existingCrew = $activeArrival->crew()
                            ->where('visitors.id', $visitor->id)
                            ->exists();

                        $noteText = "Otomatis Check-In dari Tambah Kunjungan #{$visit->ticket_number} ({$visit->purpose})";

                        if ($existingCrew) {
                            $activeArrival->crew()->updateExistingPivot($visitor->id, [
                                'status' => 'checked_in',
                                'checked_in_at' => $now,
                                'checked_out_at' => null,
                                'notes' => $noteText,
                            ]);
                        } else {
                            $activeArrival->crew()->attach($visitor->id, [
                                'status' => 'checked_in',
                                'checked_in_at' => $now,
                                'checked_out_at' => null,
                                'notes' => $noteText,
                            ]);
                        }

                        $user = $request->user();
                        VesselArrivalCrewLog::create([
                            'vessel_arrival_id' => $activeArrival->id,
                            'visitor_id' => $visitor->id,
                            'visit_id' => $visit->id,
                            'action' => 'check_in',
                            'source' => 'visit_checkin',
                            'performed_by' => $user?->id,
                            'performed_by_name' => $user?->name ?? $user?->username,
                            'notes' => $noteText,
                            'logged_at' => $now,
                        ]);

                        ActivityLogger::logEvent('vessel_crew_check_in', [
                            'vessel_arrival_id' => $activeArrival->id,
                            'vessel_name' => $activeArrival->vessel_name,
                            'visitor_id' => $visitor->id,
                            'visitor_name' => $visitor->name,
                            'visit_id' => $visit->id,
                            'ticket_number' => $visit->ticket_number,
                            'source' => 'visit_checkin',
                        ]);
                    }
                }

                return $visit;
            });

            $visit->load(['visitor', 'staff', 'location', 'visitorCards', 'vehicleCard']);

            if ($blocking !== null) {
                ActivityLogger::logEvent('blacklist_override', [
                    'hub_id' => $blocking->hub_id,
                    'id_type' => $blocking->id_type,
                    'id_number' => $blocking->id_number,
                    'name' => $blocking->name,
                    'reason_category' => $blocking->reason_category->value,
                    'registered_by_yard' => $blocking->registered_by_yard,
                    'reason' => $validated['override_reason'],
                    'visit_id' => $visit->id,
                ]);
            }

            return response()->json(array_filter([
                'message' => 'Visit recorded successfully',
                'visit' => (new VisitResource($visit))->resolve(),
                'warnings' => $warnings,
            ], fn ($value) => $value !== []), 201);

        } catch (HttpResponseException $e) {
            // A guard rejected the registration — return its response.
            return $e->getResponse();
        } catch (UniqueConstraintViolationException $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to record visit',
                'error' => UniqueViolation::isRetryable($e)
                    ? 'Could not allocate a unique number, please retry.'
                    : 'The visit conflicts with an existing record.',
            ], 500);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to record visit',
                'error' => 'An unexpected error stopped the visit from being recorded.',
            ], 500);
        }
    }

    public function update(Request $request, string $id)
    {
        $visit = Visit::findOrFail($id);

        if ($visit->checked_out_at !== null) {
            return response()->json([
                'message' => 'Cannot update a visit that has already been checked out',
            ], 422);
        }

        $validated = $request->validate([
            'location_id' => 'sometimes|uuid|exists:locations,id',
            'staff_id' => 'sometimes|nullable|uuid|exists:staff,id',
            'host_name' => 'sometimes|nullable|string|max:255',
            'purpose' => 'sometimes|string|max:255',
            'vehicle_no' => 'sometimes|nullable|string|max:255',
            'visitor_company' => 'sometimes|nullable|string|max:255',
            'card_ids' => 'sometimes|nullable|array',
            'card_ids.*' => 'bail|uuid|exists:visitor_cards,id',
            'vehicle_card_id' => 'sometimes|nullable|uuid|exists:vehicle_cards,id',
        ]);

        try {
            DB::transaction(function () use ($visit, $validated) {
                SequenceLock::acquire('ticket_number');

                // Location update
                if (array_key_exists('location_id', $validated)) {
                    $visit->location_id = $validated['location_id'];
                    $visit->location_name = Location::find($validated['location_id'])?->name;
                }

                // Staff / Host update
                if (array_key_exists('staff_id', $validated) || array_key_exists('host_name', $validated)) {
                    $staffId = array_key_exists('staff_id', $validated) ? $validated['staff_id'] : $visit->staff_id;
                    if (! empty($staffId)) {
                        $staff = Staff::find($staffId);
                        $visit->staff_id = $staffId;
                        $visit->host_name = $staff?->name;
                    } else {
                        $visit->staff_id = null;
                        $visit->host_name = array_key_exists('host_name', $validated) ? $validated['host_name'] : $visit->host_name;
                    }
                }

                // Purpose & Vehicle No
                if (array_key_exists('purpose', $validated)) {
                    $visit->purpose = $validated['purpose'];
                }
                if (array_key_exists('vehicle_no', $validated)) {
                    $visit->vehicle_no = $validated['vehicle_no'];
                }

                // Visitor company
                if (array_key_exists('visitor_company', $validated)) {
                    $visit->visitor_company = $validated['visitor_company'];
                    if ($validated['visitor_company'] !== null && $validated['visitor_company'] !== '') {
                        $visitor = $visit->visitor;
                        if ($visitor && $visitor->company !== $validated['visitor_company']) {
                            $visitor->update(['company' => $validated['visitor_company']]);
                        }
                    }
                }

                // Visitor Cards update
                if (array_key_exists('card_ids', $validated)) {
                    $newCardIds = collect($validated['card_ids'] ?? [])->filter()->unique()->values()->all();
                    $currentCardIds = $visit->visitorCards()->pluck('visitor_cards.id')->all();

                    $cardsToRelease = array_diff($currentCardIds, $newCardIds);
                    $cardsToAdd = array_diff($newCardIds, $currentCardIds);

                    // Release removed cards first
                    if (! empty($cardsToRelease)) {
                        VisitorCard::whereIn('id', $cardsToRelease)->update(['status' => 'available']);
                    }

                    // Verify new cards to add are available
                    if (! empty($cardsToAdd)) {
                        $newCards = VisitorCard::whereIn('id', $cardsToAdd)->get();
                        if ($newCards->contains('status', 'in_use')) {
                            throw new HttpResponseException(
                                response()->json(['message' => 'One or more selected Visitor Cards are in use'], 422)
                            );
                        }
                        VisitorCard::whereIn('id', $cardsToAdd)->update(['status' => 'in_use']);
                    }

                    $visit->visitorCards()->sync($newCardIds);
                }

                // Vehicle Card update
                if (array_key_exists('vehicle_card_id', $validated)) {
                    $newVehicleCardId = $validated['vehicle_card_id'];
                    $currentVehicleCardId = $visit->vehicle_card_id;

                    if ($newVehicleCardId !== $currentVehicleCardId) {
                        // Release old vehicle card if set
                        if ($currentVehicleCardId) {
                            VehicleCard::where('id', $currentVehicleCardId)->update(['status' => 'available']);
                        }

                        // Claim new vehicle card if set
                        if ($newVehicleCardId) {
                            $newVehicleCard = VehicleCard::find($newVehicleCardId);
                            if ($newVehicleCard && $newVehicleCard->status === 'in_use') {
                                throw new HttpResponseException(
                                    response()->json(['message' => 'Selected Vehicle Card is in use'], 422)
                                );
                            }
                            $newVehicleCard?->update(['status' => 'in_use']);
                        }

                        $visit->vehicle_card_id = $newVehicleCardId;
                    }
                }

                $visit->save();
            });
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to update visit',
                'error' => 'An unexpected error stopped the visit from being updated.',
            ], 500);
        }

        $visit->load(['visitor', 'staff', 'location', 'visitorCards', 'vehicleCard']);

        return response()->json([
            'message' => 'Visit updated successfully',
            'visit' => (new VisitResource($visit))->resolve(),
        ]);
    }

    public function reprint(Request $request)
    {
        $date = $request->query('date', now()->toDateString());
        $perPage = (int) $request->input('per_page', 100);
        $perPage = max(1, min($perPage, 100));

        $query = Visit::with(['visitor', 'staff', 'location', 'visitorCards', 'vehicleCard'])
            ->where('date', $date)
            ->orderBy('created_at', 'desc');

        if ($request->filled('name')) {
            $term = trim((string) $request->query('name'));
            $escaped = '%'.strtolower(addcslashes($term, '%_\\')).'%';
            $query->whereHas('visitor', function ($q) use ($escaped) {
                $q->whereRaw('LOWER(name) LIKE ?', [$escaped]);
            });
        }

        if ($request->filled('id_number')) {
            $idNumber = IdentityNumber::normalize($request->query('id_number'));
            if ($idNumber !== '') {
                $query->whereHas('visitor', function ($q) use ($idNumber) {
                    $q->where('id_number', 'like', '%'.addcslashes($idNumber, '%_\\').'%');
                });
            }
        }

        return response()->json(
            $query->paginate($perPage)->through(fn (Visit $visit): array => (new VisitResource($visit))->resolve())
        );
    }

    public function print(Request $request, $id)
    {
        $visit = Visit::with(['visitor', 'staff', 'location', 'visitorCards', 'vehicleCard'])->findOrFail($id);

        $data = (new VisitResource($visit))->resolve();
        $data['printed_by'] = $request->user()?->name ?? $request->user()?->username;

        return response()->json($data);
    }

    public function checkout($id)
    {
        $visit = Visit::findOrFail($id);

        if ($visit->checked_out_at !== null) {
            return response()->json(['message' => 'Visitor already checked out'], 422);
        }

        try {
            DB::beginTransaction();

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
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'message' => 'Checkout failed',
                'error' => 'An unexpected error stopped the checkout from being completed.',
            ], 500);
        }

        $visit->load(['visitor', 'staff', 'location', 'visitorCards', 'vehicleCard']);

        return response()->json([
            'message' => 'Checkout successful',
            'visit' => (new VisitResource($visit))->resolve(request()),
        ]);
    }

    public function destroy($id)
    {
        $visit = Visit::findOrFail($id);

        try {
            DB::beginTransaction();

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
        } catch (\Throwable $e) {
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
