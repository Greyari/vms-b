<?php

namespace App\Console\Commands;

use App\Enums\VisitorType;
use App\Models\Location;
use App\Models\VesselArrival;
use App\Models\Visitor;
use App\Support\IdentityNumber;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ImportArrivalCrewCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vms:import-arrival-crew
                            {file : Path to CSV file containing ARRIVAL TIME export}
                            {--dry-run : Simulate import without persisting changes to database}
                            {--gap-days=21 : Inactivity gap in days between arrivals to split into separate voyages}
                            {--inactive-days=21 : Days without vessel activity to consider vessel departed}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import vessel arrivals and crew assignments from VMS-old ARRIVAL TIME export';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $filePath = $this->argument('file');
        if (! File::exists($filePath)) {
            $this->error("File not found at path: [{$filePath}].");

            return 1;
        }

        $dryRun = (bool) $this->option('dry-run');
        $gapDaysThreshold = (int) $this->option('gap-days');
        $inactiveDaysThreshold = (int) $this->option('inactive-days');

        $this->info('====================================================');
        $this->info('  VMS Vessel Arrival & Crew Importer');
        $this->info("  File: {$filePath}");
        $this->info('  Mode: '.($dryRun ? 'DRY-RUN (Simulation, database will not be modified)' : 'LIVE EXECUTION (Persisting changes to database)'));
        $this->info("  Gap threshold: {$gapDaysThreshold} days | Inactivity threshold: {$inactiveDaysThreshold} days");
        $this->info('====================================================');

        $handle = fopen($filePath, 'r');
        if (! $handle) {
            $this->error("Cannot open file: [{$filePath}].");

            return 1;
        }

        $now = now();
        $rowsByImo = [];
        $totalRawRows = 0;

        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            if (empty($row) || (count($row) === 1 && $row[0] === null)) {
                continue;
            }

            // Strip UTF-8 BOM if present on first column
            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
            if (strtoupper($row[0]) === 'VISITORID') {
                continue; // Header row
            }

            $imo = trim($row[5] ?? '');
            if ($imo === '') {
                continue;
            }

            $totalRawRows++;
            $rowsByImo[$imo][] = [
                'visitor_id' => (int) $row[0],
                'name' => trim($row[1] ?? ''),
                'id_type_raw' => strtoupper(trim($row[2] ?? 'K')),
                'id_number_raw' => trim($row[3] ?? ''),
                'company' => trim($row[4] ?? ''),
                'imo' => $imo,
                'vessel_name' => trim($row[6] ?? ''),
                'is_vessel' => trim($row[7] ?? ''),
                'arrival_at' => trim($row[8] ?? ''),
                'check_in_time' => trim($row[9] ?? ''),
                'check_out_time' => trim($row[10] ?? ''),
                'employee' => trim($row[11] ?? ''),
                'location' => trim($row[12] ?? ''),
                'note' => trim($row[13] ?? ''),
                'next_arrival_at' => (trim($row[14] ?? '') === 'NULL' || trim($row[14] ?? '') === '') ? null : trim($row[14]),
                'next_arrival_imo' => (trim($row[15] ?? '') === 'NULL' || trim($row[15] ?? '') === '') ? null : trim($row[15]),
                'crew_last_seen' => (trim($row[16] ?? '') === 'NULL' || trim($row[16] ?? '') === '') ? null : trim($row[16]),
                'crew_tx_count' => (int) ($row[17] ?? 0),
                'vessel_last_seen' => (trim($row[18] ?? '') === 'NULL' || trim($row[18] ?? '') === '') ? null : trim($row[18]),
                'vessel_tx_count' => (int) ($row[19] ?? 0),
            ];
        }
        fclose($handle);

        $this->info("Read {$totalRawRows} arrival rows across ".count($rowsByImo).' distinct vessels.');

        // Pre-load reference maps
        $locMap = Location::withTrashed()->pluck('id', 'code')->all();
        $visitorMap = Visitor::whereNotNull('visitor_no')->pluck('id', 'visitor_no')->all();
        $visitorByIdMap = Visitor::pluck('id', 'id_number')->all();

        $arrivalsCreated = 0;
        $arrivalsReused = 0;
        $inPortCount = 0;
        $departedCount = 0;
        $crewPlaced = 0;
        $crewCheckedIn = 0;
        $crewCheckedOut = 0;
        $autoCreatedVisitors = 0;

        $dbWork = function () use (
            $rowsByImo,
            $locMap,
            &$visitorMap,
            &$visitorByIdMap,
            $dryRun,
            $gapDaysThreshold,
            $inactiveDaysThreshold,
            $now,
            &$arrivalsCreated,
            &$arrivalsReused,
            &$inPortCount,
            &$departedCount,
            &$crewPlaced,
            &$crewCheckedIn,
            &$crewCheckedOut,
            &$autoCreatedVisitors
        ) {
            foreach ($rowsByImo as $imo => $rows) {
                if (! isset($locMap[$imo])) {
                    $this->warn("Skipping unknown vessel IMO: [{$imo}] ({$rows[0]['vessel_name']})");

                    continue;
                }

                $locationId = $locMap[$imo];

                // Sort arrivals chronologically
                usort($rows, fn ($a, $b) => strcmp($a['arrival_at'], $b['arrival_at']));

                // Break into distinct voyages if gap exceeds threshold
                $voyages = [];
                $currentVoyage = [];
                $lastArrivalDate = null;

                foreach ($rows as $r) {
                    $arrDate = Carbon::parse($r['arrival_at']);
                    if ($lastArrivalDate !== null && $arrDate->diffInDays($lastArrivalDate) > $gapDaysThreshold) {
                        $voyages[] = $currentVoyage;
                        $currentVoyage = [];
                    }
                    $currentVoyage[] = $r;
                    $lastArrivalDate = $arrDate;
                }
                if (! empty($currentVoyage)) {
                    $voyages[] = $currentVoyage;
                }

                foreach ($voyages as $vIdx => $voyageRows) {
                    $firstArrival = $voyageRows[0]['arrival_at'];
                    $signInDate = substr($firstArrival, 0, 10);
                    $vesselLastSeen = $voyageRows[0]['vessel_last_seen'];

                    $isLatestVoyage = ($vIdx === count($voyages) - 1);
                    $daysSinceVesselActivity = $vesselLastSeen ? Carbon::parse($vesselLastSeen)->diffInDays($now) : 999;

                    $signOutDate = null;
                    if (! $isLatestVoyage || $daysSinceVesselActivity > $inactiveDaysThreshold) {
                        $signOutDate = $vesselLastSeen ? substr($vesselLastSeen, 0, 10) : $signInDate;
                        if ($signOutDate < $signInDate) {
                            $signOutDate = $signInDate;
                        }
                        $departedCount++;
                    } else {
                        $inPortCount++;
                    }

                    $vesselArrivalId = null;

                    // Check if VesselArrival already exists for this vessel & date
                    $existingArrival = VesselArrival::where('location_id', $locationId)
                        ->where('sign_in_date', $signInDate)
                        ->first();

                    if ($existingArrival) {
                        $vesselArrivalId = $existingArrival->id;
                        $arrivalsReused++;
                        if (! $dryRun) {
                            // Update sign_out_date if needed
                            if ($existingArrival->sign_out_date === null && $signOutDate !== null) {
                                $existingArrival->sign_out_date = $signOutDate;
                                $existingArrival->save();
                            }
                        }
                    } else {
                        $arrivalsCreated++;
                        if (! $dryRun) {
                            $arrival = new VesselArrival([
                                'location_id' => $locationId,
                                'vessel_name' => $voyageRows[0]['vessel_name'] ?: 'VESSEL',
                                'sign_in_date' => $signInDate,
                                'sign_out_date' => $signOutDate,
                                'sign_in_port' => 'BATAM',
                                'captain' => null,
                            ]);
                            $arrival->id = (string) Str::uuid7();
                            $arrival->save();
                            $vesselArrivalId = $arrival->id;
                        } else {
                            $vesselArrivalId = 'simulated-arrival-'.$arrivalsCreated;
                        }
                    }

                    // Process crew for this arrival
                    $seenCrewInVoyage = [];
                    foreach ($voyageRows as $cr) {
                        $vNo = $cr['visitor_id'];
                        $uuid = $visitorMap[$vNo] ?? ($visitorByIdMap[$cr['id_number_raw']] ?? null);

                        if (! $uuid) {
                            // Visitor not found: auto-create visitor record
                            $idType = match ($cr['id_type_raw']) {
                                'P' => 'passport',
                                'S' => 'sim',
                                default => 'ktp',
                            };
                            $idNumber = IdentityNumber::normalize($cr['id_number_raw']);

                            if (! $dryRun) {
                                $newVisitor = new Visitor([
                                    'visitor_no' => $vNo > 0 ? $vNo : null,
                                    'name' => $cr['name'] ?: 'CREW',
                                    'id_type' => $idType,
                                    'id_number' => $idNumber,
                                    'company' => $cr['company'] ?: $cr['vessel_name'],
                                    'visitor_type' => VisitorType::Crew,
                                    'vessel_id' => $locationId,
                                ]);
                                $newVisitor->id = (string) Str::uuid7();
                                $newVisitor->save();
                                $uuid = $newVisitor->id;

                                if ($vNo > 0) {
                                    $visitorMap[$vNo] = $uuid;
                                }
                                $visitorByIdMap[$idNumber] = $uuid;
                            } else {
                                $uuid = 'simulated-visitor-'.$vNo;
                            }
                            $autoCreatedVisitors++;
                        }

                        if (isset($seenCrewInVoyage[$uuid])) {
                            continue; // Skip duplicate record of same crew in same voyage
                        }
                        $seenCrewInVoyage[$uuid] = true;

                        $hasMoved = ! empty($cr['next_arrival_at']);
                        $isDeparted = ($signOutDate !== null);

                        if ($isDeparted || $hasMoved) {
                            $status = 'checked_out';
                            $checkedInAt = Carbon::parse($cr['arrival_at']);
                            $checkedOutAt = $hasMoved
                                ? Carbon::parse($cr['next_arrival_at'])
                                : ($cr['crew_last_seen'] ? Carbon::parse($cr['crew_last_seen']) : Carbon::parse($signOutDate));
                            $crewCheckedOut++;
                        } else {
                            $status = 'checked_in';
                            $checkedInAt = Carbon::parse($cr['arrival_at']);
                            $checkedOutAt = null;
                            $crewCheckedIn++;
                        }

                        $crewPlaced++;

                        if (! $dryRun) {
                            // Check existing pivot
                            $existingPivot = DB::table('vessel_arrival_crew')
                                ->where('vessel_arrival_id', $vesselArrivalId)
                                ->where('visitor_id', $uuid)
                                ->first();

                            $pivotId = $existingPivot ? $existingPivot->id : (string) Str::uuid7();

                            if ($existingPivot) {
                                DB::table('vessel_arrival_crew')
                                    ->where('id', $pivotId)
                                    ->update([
                                        'status' => $status,
                                        'checked_in_at' => $checkedInAt,
                                        'checked_out_at' => $checkedOutAt,
                                        'notes' => 'Imported from VMS-old ARRIVAL TIME record',
                                        'updated_at' => now(),
                                    ]);
                            } else {
                                DB::table('vessel_arrival_crew')->insert([
                                    'id' => $pivotId,
                                    'vessel_arrival_id' => $vesselArrivalId,
                                    'visitor_id' => $uuid,
                                    'status' => $status,
                                    'checked_in_at' => $checkedInAt,
                                    'checked_out_at' => $checkedOutAt,
                                    'notes' => 'Imported from VMS-old ARRIVAL TIME record',
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            }

                            // Update visitor current vessel_id if active
                            if ($status === 'checked_in') {
                                DB::table('visitors')
                                    ->where('id', $uuid)
                                    ->update([
                                        'vessel_id' => $locationId,
                                        'visitor_type' => VisitorType::Crew->value,
                                    ]);
                            }

                            // Write history log for check_in
                            DB::table('vessel_arrival_crew_logs')->insert([
                                'id' => (string) Str::uuid7(),
                                'vessel_arrival_id' => $vesselArrivalId,
                                'visitor_id' => $uuid,
                                'visit_id' => null,
                                'action' => 'check_in',
                                'source' => 'vessel_signon',
                                'performed_by' => null,
                                'performed_by_name' => 'System Importer',
                                'notes' => 'Imported from VMS-old ARRIVAL TIME manifest',
                                'logged_at' => $checkedInAt,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);

                            // Write history log for check_out if checked out
                            if ($status === 'checked_out' && $checkedOutAt) {
                                DB::table('vessel_arrival_crew_logs')->insert([
                                    'id' => (string) Str::uuid7(),
                                    'vessel_arrival_id' => $vesselArrivalId,
                                    'visitor_id' => $uuid,
                                    'visit_id' => null,
                                    'action' => 'check_out',
                                    'source' => $hasMoved ? 'manual' : 'vessel_signout',
                                    'performed_by' => null,
                                    'performed_by_name' => 'System Importer',
                                    'notes' => $hasMoved ? "Transferred to vessel (IMO: {$cr['next_arrival_imo']})" : 'Vessel departure',
                                    'logged_at' => $checkedOutAt,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            }
                        }
                    }
                }
            }
        };

        if ($dryRun) {
            $dbWork();
        } else {
            DB::transaction($dbWork);
        }

        $this->info('');
        $this->info('================ IMPORT SUMMARY ================');
        $this->info('Total Vessels Processed     : '.count($rowsByImo));
        $this->info("Vessel Arrivals Created     : {$arrivalsCreated}");
        $this->info("Vessel Arrivals Reused      : {$arrivalsReused}");
        $this->info(" - In Port (Active in Yard) : {$inPortCount}");
        $this->info(" - Departed (Past Voyages)  : {$departedCount}");
        $this->info("Total Crew Placements       : {$crewPlaced}");
        $this->info(" - Checked In (Active)      : {$crewCheckedIn}");
        $this->info(" - Checked Out (Completed)  : {$crewCheckedOut}");
        $this->info("Auto-created New Visitors   : {$autoCreatedVisitors}");
        $this->info('================================================');

        if ($dryRun) {
            $this->warn('DRY RUN COMPLETE: No changes were persisted to database.');
            $this->warn('To execute the import and persist changes, run without the --dry-run option:');
            $this->line("php artisan vms:import-arrival-crew \"{$filePath}\"");
        } else {
            $this->info('IMPORT COMPLETED: All vessel arrivals and crew assignments have been persisted successfully!');
        }

        return 0;
    }
}
