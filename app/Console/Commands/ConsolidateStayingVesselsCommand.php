<?php

namespace App\Console\Commands;

use App\Enums\LocationType;
use App\Models\Location;
use App\Models\VesselArrival;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConsolidateStayingVesselsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vms:consolidate-staying-vessels
                            {--dry-run : Simulate consolidation without modifying the database}';

    /**
     * The console command aliases.
     *
     * @var array<int, string>
     */
    protected $aliases = ['vms:merge-staying-vessels'];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consolidate duplicate arrival records for staying vessels (Tugboats & Barges) and reopen checked-out ones so they remain permanently active in the yard';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('====================================================');
        $this->info('  VMS Staying Vessels Consolidation (Tugboat & Barge)');
        $this->info('  Mode: '.($dryRun ? 'DRY-RUN (Simulation, database will NOT be modified)' : 'LIVE EXECUTION (Persisting changes)'));
        $this->info('====================================================');

        // 1. Fetch all locations that are staying vessels
        $stayingLocations = Location::whereIn('type', [LocationType::TugBoat, LocationType::Barge])
            ->orWhereIn('vessel_type', ['tug_boat', 'barge'])
            ->get();

        if ($stayingLocations->isEmpty()) {
            $this->info('No staying vessels (Tugboat/Barge) found in master locations.');

            return self::SUCCESS;
        }

        // Group locations by normalized name
        $groupedLocations = $stayingLocations->groupBy(function (Location $loc) {
            return trim(preg_replace('/\s+/u', ' ', strtolower((string) $loc->name)));
        });

        $this->info("Found {$stayingLocations->count()} staying vessel locations across {$groupedLocations->count()} unique names.");

        $reopenedCount = 0;
        $mergedVesselsCount = 0;
        $deletedArrivalsCount = 0;
        $migratedCrewCount = 0;
        $migratedLogsCount = 0;

        $run = function () use (
            $groupedLocations,
            $dryRun,
            &$reopenedCount,
            &$mergedVesselsCount,
            &$deletedArrivalsCount,
            &$migratedCrewCount,
            &$migratedLogsCount
        ) {
            foreach ($groupedLocations as $normalizedName => $locations) {
                $locationIds = $locations->pluck('id')->all();
                $primaryLocation = $locations->first();

                // Find all arrivals for these location IDs
                $arrivals = VesselArrival::whereIn('location_id', $locationIds)
                    ->orderBy('sign_in_date', 'desc')
                    ->orderBy('created_at', 'desc')
                    ->get();

                if ($arrivals->isEmpty()) {
                    continue;
                }

                if ($arrivals->count() === 1) {
                    $singleArrival = $arrivals->first();
                    if ($singleArrival->sign_out_date !== null) {
                        $this->line("• Reopening checked-out staying vessel: [{$singleArrival->vessel_name}] (Arrival ID: {$singleArrival->id})");
                        $reopenedCount++;
                        if (! $dryRun) {
                            $singleArrival->update([
                                'sign_out_date' => null,
                                'sign_out_time' => null,
                                'sign_out_port' => null,
                            ]);
                        }
                    }

                    continue;
                }

                // If multiple arrivals exist for this staying vessel, merge them!
                $this->warn("• Consolidating {$arrivals->count()} arrivals for staying vessel: [{$primaryLocation->name}]");
                $mergedVesselsCount++;

                // Pick master arrival:
                // 1) Prefer currently active (sign_out_date is null)
                // 2) If multiple or none, pick newest by sign_in_date / created_at
                $activeArrivals = $arrivals->whereNull('sign_out_date');
                $masterArrival = $activeArrivals->isNotEmpty() ? $activeArrivals->first() : $arrivals->first();
                $secondaryArrivals = $arrivals->reject(fn ($a) => $a->id === $masterArrival->id);

                $this->line("  -> Master Arrival ID: {$masterArrival->id} (Sign-in: {$masterArrival->sign_in_date?->format('Y-m-d')})");

                // Ensure master arrival is reopened / stays active
                $masterUpdates = [
                    'location_id' => $primaryLocation->id,
                    'vessel_name' => $primaryLocation->name,
                    'sign_out_date' => null,
                    'sign_out_time' => null,
                    'sign_out_port' => null,
                ];

                foreach ($secondaryArrivals as $secondary) {
                    $this->line("  -> Merging Secondary Arrival ID: {$secondary->id} (Sign-in: {$secondary->sign_in_date?->format('Y-m-d')})");

                    // Keep earliest sign_in_date if secondary was earlier
                    if ($secondary->sign_in_date && (! $masterArrival->sign_in_date || $secondary->sign_in_date < $masterArrival->sign_in_date)) {
                        $masterUpdates['sign_in_date'] = $secondary->sign_in_date;
                    }

                    // Fill missing master metadata from secondary
                    foreach (['captain', 'local_agent_company', 'local_agent_name', 'local_agent_phone', 'local_agent_location', 'foreign_agent_company', 'foreign_agent_name', 'foreign_agent_phone', 'foreign_agent_location'] as $field) {
                        if (empty($masterArrival->{$field}) && ! empty($secondary->{$field})) {
                            $masterUpdates[$field] = $secondary->{$field};
                        }
                    }

                    // Migrate crew from secondary arrival
                    $secondaryCrewRows = DB::table('vessel_arrival_crew')
                        ->where('vessel_arrival_id', $secondary->id)
                        ->get();

                    foreach ($secondaryCrewRows as $secCrew) {
                        $migratedCrewCount++;

                        if ($dryRun) {
                            continue;
                        }

                        // Check if master already has this crew member
                        $existingMasterCrew = DB::table('vessel_arrival_crew')
                            ->where('vessel_arrival_id', $masterArrival->id)
                            ->where('visitor_id', $secCrew->visitor_id)
                            ->first();

                        if ($existingMasterCrew) {
                            // Delete secondary row first to avoid any unique constraint collision
                            DB::table('vessel_arrival_crew')
                                ->where('id', $secCrew->id)
                                ->delete();

                            // If secondary had an active status, promote master crew placement
                            if (in_array($secCrew->status, ['expected', 'checked_in'], true) && ! in_array($existingMasterCrew->status, ['expected', 'checked_in'], true)) {
                                DB::table('vessel_arrival_crew')
                                    ->where('id', $existingMasterCrew->id)
                                    ->update([
                                        'status' => $secCrew->status,
                                        'checked_in_at' => $secCrew->checked_in_at ?? $existingMasterCrew->checked_in_at,
                                        'checked_out_at' => null,
                                    ]);
                            }
                        } else {
                            // Move placement to master
                            DB::table('vessel_arrival_crew')
                                ->where('id', $secCrew->id)
                                ->update([
                                    'vessel_arrival_id' => $masterArrival->id,
                                ]);
                        }
                    }

                    // Migrate logs from secondary arrival
                    $logCount = DB::table('vessel_arrival_crew_logs')
                        ->where('vessel_arrival_id', $secondary->id)
                        ->count();

                    $migratedLogsCount += $logCount;

                    if (! $dryRun) {
                        DB::table('vessel_arrival_crew_logs')
                            ->where('vessel_arrival_id', $secondary->id)
                            ->update([
                                'vessel_arrival_id' => $masterArrival->id,
                            ]);

                        // Delete secondary arrival
                        $secondary->delete();
                        $deletedArrivalsCount++;
                    } else {
                        $deletedArrivalsCount++;
                    }
                }

                if (! $dryRun) {
                    $masterArrival->update($masterUpdates);
                }
            }
        };

        if ($dryRun) {
            $run();
        } else {
            DB::transaction($run);
        }

        $this->newLine();
        $this->info('--- Consolidation Summary ---');
        $this->info("Checked-out staying vessels reopened to active: {$reopenedCount}");
        $this->info("Staying vessels with duplicate arrivals merged: {$mergedVesselsCount}");
        $this->info("Redundant duplicate arrivals removed: {$deletedArrivalsCount}");
        $this->info("Crew placements migrated / resolved: {$migratedCrewCount}");
        $this->info("Crew history logs re-linked: {$migratedLogsCount}");
        $this->info('Consolidation completed successfully.');

        return self::SUCCESS;
    }
}
