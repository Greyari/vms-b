<?php

namespace App\Services;

use App\Models\User;
use App\Models\VesselArrival;
use App\Models\VesselArrivalCrew;
use App\Models\VesselArrivalCrewLog;
use App\Models\Visitor;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VesselCrewService
{
    /**
     * Check in one or more crew members to a vessel arrival.
     *
     * @param  array<int, string>  $visitorIds
     * @return array<string, mixed>
     */
    public function checkIn(
        VesselArrival $arrival,
        array $visitorIds,
        ?string $checkedInAt = null,
        ?string $notes = null,
        ?User $user = null,
        bool $allowTransfer = false
    ): array {
        $visitorIds = array_values(array_unique(array_filter($visitorIds)));
        if (empty($visitorIds)) {
            return ['count' => 0];
        }

        // 1. Blacklist check
        $blacklisted = Visitor::whereIn('id', $visitorIds)->where('is_blacklisted', true)->first();
        if ($blacklisted) {
            throw ValidationException::withMessages([
                'crew' => ["Kru {$blacklisted->name} berstatus blacklist dan tidak dapat di-check-in."],
            ]);
        }

        // 2. Active placement conflict check on other vessels
        $conflicts = VesselArrivalCrew::whereIn('visitor_id', $visitorIds)
            ->where('vessel_arrival_id', '!=', $arrival->id)
            ->whereIn('status', ['expected', 'checked_in'])
            ->with(['vesselArrival', 'visitor'])
            ->get();

        if ($conflicts->isNotEmpty()) {
            if ($allowTransfer) {
                foreach ($conflicts as $conflict) {
                    $this->transfer($conflict->visitor_id, $arrival->id, $conflict->vessel_arrival_id, $notes, $user);
                }
                // Filter out transferred ids from subsequent standard attach since transfer already attached them
                $transferredIds = $conflicts->pluck('visitor_id')->all();
                $visitorIds = array_diff($visitorIds, $transferredIds);
                if (empty($visitorIds)) {
                    return ['count' => count($transferredIds)];
                }
            } else {
                $conflict = $conflicts->first();
                $visitorName = $conflict->visitor?->name ?? 'Kru';
                $vesselName = $conflict->vesselArrival?->vessel_name ?? 'kapal lain';
                throw ValidationException::withMessages([
                    'conflict' => ["Kru {$visitorName} masih aktif di kapal {$vesselName}. Silakan lakukan pindah kapal."],
                    'conflict_visitor_id' => [$conflict->visitor_id],
                    'conflict_vessel_name' => [$vesselName],
                    'conflict_arrival_id' => [$conflict->vessel_arrival_id],
                ]);
            }
        }

        $checkInTime = $checkedInAt ? Carbon::parse($checkedInAt) : now();

        DB::transaction(function () use ($arrival, $visitorIds, $checkInTime, $notes, $user) {
            $existingPivots = DB::table('vessel_arrival_crew')
                ->where('vessel_arrival_id', $arrival->id)
                ->whereIn('visitor_id', $visitorIds)
                ->pluck('status', 'visitor_id')
                ->all();

            foreach ($visitorIds as $visitorId) {
                if (array_key_exists($visitorId, $existingPivots)) {
                    $arrival->crew()->updateExistingPivot($visitorId, [
                        'status' => 'checked_in',
                        'checked_in_at' => $checkInTime,
                        'checked_out_at' => null,
                        'notes' => $notes,
                    ]);
                } else {
                    $arrival->crew()->attach($visitorId, [
                        'status' => 'checked_in',
                        'checked_in_at' => $checkInTime,
                        'checked_out_at' => null,
                        'notes' => $notes,
                    ]);
                }

                VesselArrivalCrewLog::create([
                    'vessel_arrival_id' => $arrival->id,
                    'visitor_id' => $visitorId,
                    'action' => 'check_in',
                    'source' => 'manual',
                    'performed_by' => $user?->id,
                    'performed_by_name' => $user?->name ?? $user?->username ?? 'System',
                    'notes' => $notes ?? 'Check-In kru di kapal',
                    'logged_at' => $checkInTime,
                ]);
            }

            Visitor::whereIn('id', $visitorIds)->update(['vessel_id' => $arrival->location_id]);
        });

        ActivityLogger::logEvent('vessel_crew_check_in', [
            'vessel_arrival_id' => $arrival->id,
            'vessel_name' => $arrival->vessel_name,
            'crew_ids' => $visitorIds,
            'notes' => $notes,
        ]);

        return ['count' => count($visitorIds)];
    }

    /**
     * Check out one or more crew members from a vessel arrival.
     *
     * @param  array<int, string>  $visitorIds
     * @return array<string, mixed>
     */
    public function checkOut(
        VesselArrival $arrival,
        array $visitorIds,
        ?string $checkedOutAt = null,
        ?string $notes = null,
        ?User $user = null
    ): array {
        $visitorIds = array_values(array_unique(array_filter($visitorIds)));
        if (empty($visitorIds)) {
            return ['count' => 0];
        }

        $checkOutTime = $checkedOutAt ? Carbon::parse($checkedOutAt) : now();

        DB::transaction(function () use ($arrival, $visitorIds, $checkOutTime, $notes, $user) {
            foreach ($visitorIds as $visitorId) {
                $pivotData = [
                    'status' => 'checked_out',
                    'checked_out_at' => $checkOutTime,
                ];
                if ($notes !== null) {
                    $pivotData['notes'] = $notes;
                }
                $arrival->crew()->updateExistingPivot($visitorId, $pivotData);

                VesselArrivalCrewLog::create([
                    'vessel_arrival_id' => $arrival->id,
                    'visitor_id' => $visitorId,
                    'action' => 'check_out',
                    'source' => 'manual',
                    'performed_by' => $user?->id,
                    'performed_by_name' => $user?->name ?? $user?->username ?? 'System',
                    'notes' => $notes ?? 'Check-Out kru dari kapal',
                    'logged_at' => $checkOutTime,
                ]);
            }
        });

        ActivityLogger::logEvent('vessel_crew_check_out', [
            'vessel_arrival_id' => $arrival->id,
            'vessel_name' => $arrival->vessel_name,
            'crew_ids' => $visitorIds,
            'notes' => $notes,
        ]);

        return ['count' => count($visitorIds)];
    }

    /**
     * Reactivate a checked_out crew member on a vessel arrival.
     *
     * @return array<string, mixed>
     */
    public function reactivate(
        VesselArrival $arrival,
        string $visitorId,
        ?string $notes = null,
        ?User $user = null
    ): array {
        $visitor = Visitor::findOrFail($visitorId);

        if ($visitor->is_blacklisted) {
            throw ValidationException::withMessages([
                'crew' => ["Kru {$visitor->name} berstatus blacklist dan tidak dapat diaktifkan kembali."],
            ]);
        }

        // Check if currently active on another vessel arrival
        $conflict = VesselArrivalCrew::where('visitor_id', $visitorId)
            ->where('vessel_arrival_id', '!=', $arrival->id)
            ->whereIn('status', ['expected', 'checked_in'])
            ->with(['vesselArrival'])
            ->first();

        if ($conflict) {
            $otherVessel = $conflict->vesselArrival?->vessel_name ?? 'kapal lain';
            throw ValidationException::withMessages([
                'conflict' => ["Kru {$visitor->name} saat ini aktif di kapal {$otherVessel}. Lakukan pindah kapal bila ingin memindahkan."],
            ]);
        }

        $now = now();

        DB::transaction(function () use ($arrival, $visitorId, $now, $notes, $user) {
            $arrival->crew()->updateExistingPivot($visitorId, [
                'status' => 'checked_in',
                'checked_in_at' => $now,
                'checked_out_at' => null,
                'notes' => $notes,
            ]);

            VesselArrivalCrewLog::create([
                'vessel_arrival_id' => $arrival->id,
                'visitor_id' => $visitorId,
                'action' => 'check_in',
                'source' => 'manual',
                'performed_by' => $user?->id,
                'performed_by_name' => $user?->name ?? $user?->username ?? 'System',
                'notes' => 'Check-In Kembali'.($notes ? ": {$notes}" : ''),
                'logged_at' => $now,
            ]);

            Visitor::where('id', $visitorId)->update(['vessel_id' => $arrival->location_id]);
        });

        ActivityLogger::logEvent('vessel_crew_reactivate', [
            'vessel_arrival_id' => $arrival->id,
            'vessel_name' => $arrival->vessel_name,
            'visitor_id' => $visitorId,
            'notes' => $notes,
        ]);

        return ['success' => true];
    }

    /**
     * Transfer a crew member from one vessel arrival to another in a single transaction.
     *
     * @return array<string, mixed>
     */
    public function transfer(
        string $visitorId,
        string $targetArrivalId,
        ?string $sourceArrivalId = null,
        ?string $notes = null,
        ?User $user = null
    ): array {
        $visitor = Visitor::findOrFail($visitorId);
        if ($visitor->is_blacklisted) {
            throw ValidationException::withMessages([
                'crew' => ["Kru {$visitor->name} berstatus blacklist dan tidak dapat dipindahkan."],
            ]);
        }

        $targetArrival = VesselArrival::findOrFail($targetArrivalId);

        // Find active placement
        $activePlacementQuery = VesselArrivalCrew::where('visitor_id', $visitorId)
            ->whereIn('status', ['expected', 'checked_in']);

        if ($sourceArrivalId) {
            $activePlacementQuery->where('vessel_arrival_id', $sourceArrivalId);
        }

        $activePlacement = $activePlacementQuery->with('vesselArrival')->first();

        if ($activePlacement && $activePlacement->vessel_arrival_id === $targetArrivalId) {
            return ['already_on_target' => true];
        }

        $now = now();

        DB::transaction(function () use ($visitor, $targetArrival, $activePlacement, $now, $notes, $user) {
            // 1. Check-out from source arrival if currently active
            if ($activePlacement) {
                $sourceArrival = $activePlacement->vesselArrival;
                $activePlacement->update([
                    'status' => 'checked_out',
                    'checked_out_at' => $now,
                    'notes' => 'Pindah ke kapal '.$targetArrival->vessel_name,
                ]);

                VesselArrivalCrewLog::create([
                    'vessel_arrival_id' => $activePlacement->vessel_arrival_id,
                    'visitor_id' => $visitor->id,
                    'action' => 'check_out',
                    'source' => 'manual',
                    'performed_by' => $user?->id,
                    'performed_by_name' => $user?->name ?? $user?->username ?? 'System',
                    'notes' => 'Pindah ke kapal '.$targetArrival->vessel_name.($notes ? ": {$notes}" : ''),
                    'logged_at' => $now,
                ]);
            }

            // 2. Attach or update on target arrival
            $existingTargetPivot = DB::table('vessel_arrival_crew')
                ->where('vessel_arrival_id', $targetArrival->id)
                ->where('visitor_id', $visitor->id)
                ->exists();

            $sourceName = $activePlacement?->vesselArrival?->vessel_name ?? 'kapal sebelumnya';
            $transferNotes = 'Pindahan dari kapal '.$sourceName.($notes ? ": {$notes}" : '');

            if ($existingTargetPivot) {
                $targetArrival->crew()->updateExistingPivot($visitor->id, [
                    'status' => 'checked_in',
                    'checked_in_at' => $now,
                    'checked_out_at' => null,
                    'notes' => $transferNotes,
                ]);
            } else {
                $targetArrival->crew()->attach($visitor->id, [
                    'status' => 'checked_in',
                    'checked_in_at' => $now,
                    'checked_out_at' => null,
                    'notes' => $transferNotes,
                ]);
            }

            VesselArrivalCrewLog::create([
                'vessel_arrival_id' => $targetArrival->id,
                'visitor_id' => $visitor->id,
                'action' => 'check_in',
                'source' => 'manual',
                'performed_by' => $user?->id,
                'performed_by_name' => $user?->name ?? $user?->username ?? 'System',
                'notes' => $transferNotes,
                'logged_at' => $now,
            ]);

            // Update visitor vessel assignment
            $visitor->update(['vessel_id' => $targetArrival->location_id]);
        });

        ActivityLogger::logEvent('vessel_crew_transfer', [
            'visitor_id' => $visitor->id,
            'source_arrival_id' => $activePlacement?->vessel_arrival_id,
            'source_vessel_name' => $activePlacement?->vesselArrival?->vessel_name,
            'target_arrival_id' => $targetArrival->id,
            'target_vessel_name' => $targetArrival->vessel_name,
            'notes' => $notes,
        ]);

        return ['success' => true];
    }
}
