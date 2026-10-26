<?php

namespace App\Console\Commands;

use App\Models\VesselArrivalCrew;
use App\Models\VesselArrivalCrewLog;
use App\Models\Visitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileCrewPlacementsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vms:reconcile-crew-placements
                            {--dry-run : Simulate reconciliation without modifying the database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile duplicate crew placements across vessels so each crew member is attached only to their active/latest vessel';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('====================================================');
        $this->info('  VMS Crew Placement Reconciliation');
        $this->info('  Mode: '.($dryRun ? 'DRY-RUN (Simulation, database will NOT be modified)' : 'LIVE EXECUTION (Persisting changes)'));
        $this->info('====================================================');

        // Find visitors with multiple placements in vessel_arrival_crew
        $multiVisitorIds = DB::table('vessel_arrival_crew')
            ->select('visitor_id', DB::raw('count(*) as c'))
            ->groupBy('visitor_id')
            ->havingRaw('count(*) > 1')
            ->pluck('visitor_id');

        $this->info("Found {$multiVisitorIds->count()} crew members with historical placements across multiple vessels.");

        $reconciledCount = 0;
        $logsEnsuredCount = 0;

        foreach ($multiVisitorIds as $visitorId) {
            $visitor = Visitor::find($visitorId);
            if (! $visitor) {
                continue;
            }

            // Placements for this visitor ordered by active status first, then newest check-in/creation
            $placements = VesselArrivalCrew::where('visitor_id', $visitorId)
                ->join('vessel_arrivals', 'vessel_arrival_crew.vessel_arrival_id', '=', 'vessel_arrivals.id')
                ->select([
                    'vessel_arrival_crew.*',
                    'vessel_arrivals.vessel_name',
                    'vessel_arrivals.location_id as vessel_location_id',
                    'vessel_arrivals.sign_out_date',
                ])
                ->orderByRaw("CASE WHEN vessel_arrival_crew.status IN ('expected', 'checked_in') THEN 0 ELSE 1 END")
                ->orderBy('vessel_arrival_crew.checked_in_at', 'desc')
                ->orderBy('vessel_arrival_crew.created_at', 'desc')
                ->get();

            if ($placements->count() <= 1) {
                continue;
            }

            // The primary / active placement is the first one
            $primary = $placements->first();
            $obsolete = $placements->slice(1);

            $this->line("Crew: <comment>{$visitor->name}</comment> (VisitorNo: {$visitor->visitor_no})");
            $this->line("  -> Keeping active/primary on: <info>{$primary->vessel_name}</info> [{$primary->status}]");

            foreach ($obsolete as $obs) {
                // Only clean up if the obsolete placement is checked_out or superseded
                if (in_array($obs->status, ['expected', 'checked_in'], true) && in_array($primary->status, ['expected', 'checked_in'], true)) {
                    // Both are active: cannot happen with unique constraint, but if present, skip or close
                    $this->warn("  -> Skipping ambiguous active duplicate on {$obs->vessel_name}");

                    continue;
                }

                $this->line("  -> Removing obsolete placement from: <fg=red>{$obs->vessel_name}</fg=red> [{$obs->status}]");

                if (! $dryRun) {
                    // Ensure an audit log exists in vessel_arrival_crew_logs before removing the pivot
                    $hasLog = VesselArrivalCrewLog::where('vessel_arrival_id', $obs->vessel_arrival_id)
                        ->where('visitor_id', $visitor->id)
                        ->exists();

                    if (! $hasLog) {
                        VesselArrivalCrewLog::create([
                            'vessel_arrival_id' => $obs->vessel_arrival_id,
                            'visitor_id' => $visitor->id,
                            'action' => 'check_out',
                            'source' => 'manual',
                            'performed_by' => null,
                            'performed_by_name' => 'System Reconciler',
                            'notes' => 'Arsip historis sebelum mutasi ke '.$primary->vessel_name,
                            'logged_at' => $obs->checked_out_at ?? $obs->updated_at ?? now(),
                        ]);
                        $logsEnsuredCount++;
                    }

                    // Safely remove the obsolete pivot row so old vessel manifest doesn't retain them
                    DB::table('vessel_arrival_crew')->where('id', $obs->id)->delete();
                    $reconciledCount++;
                } else {
                    $reconciledCount++;
                }
            }

            // Sync visitor's main vessel_id to primary vessel location
            if (! $dryRun && $primary->vessel_location_id && $visitor->vessel_id !== $primary->vessel_location_id) {
                $visitor->update(['vessel_id' => $primary->vessel_location_id]);
            }
        }

        $this->newLine();
        $this->info("Reconciliation summary: {$reconciledCount} obsolete placement rows processed across multiple vessels.");
        if (! $dryRun && $logsEnsuredCount > 0) {
            $this->info("Ensured {$logsEnsuredCount} historical audit log records before cleanup.");
        }

        return 0;
    }
}
