<?php

namespace App\Console\Commands;

use App\Models\VesselArrival;
use App\Services\ActivityLogger;
use App\Services\VesselCrewService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class CheckVesselCrewCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vms:check-vessel-crew
                            {search? : Optional search term for vessel name or IMO number}
                            {--imo= : Filter by exact or partial IMO number}
                            {--name= : Filter by exact or partial vessel name}
                            {--arrival-id= : Filter by specific vessel arrival UUID}
                            {--in-port : Show only vessels currently in port (sign_out_date is null)}
                            {--departed : Show only vessels that have departed (sign_out_date is not null)}
                            {--active-only : Show only vessels with at least one active (checked_in) crew member}
                            {--detail : Display individual crew roster under each vessel arrival}
                            {--checkout-crew : Check out all active (checked_in) crew on matching vessel(s)}
                            {--checkout-vessel : Sign out (depart) matching vessel arrival(s) if still in port}
                            {--date= : Custom checkout/sign-out datetime (YYYY-MM-DD or YYYY-MM-DD HH:mm:ss, default: now)}
                            {--notes= : Audit notes for checkout}
                            {--force : Bypass interactive confirmation prompt for live actions}
                            {--dry-run : Simulate actions without modifying the database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inspect vessel arrivals, verify crew status by IMO/name, and perform manual bulk checkout if needed';

    /**
     * Execute the console command.
     */
    public function handle(VesselCrewService $crewService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $showDetail = (bool) $this->option('detail');
        $doCheckoutCrew = (bool) $this->option('checkout-crew');
        $doCheckoutVessel = (bool) $this->option('checkout-vessel');

        $searchTerm = trim((string) ($this->argument('search') ?? ''));
        $imoFilter = trim((string) ($this->option('imo') ?? ''));
        $nameFilter = trim((string) ($this->option('name') ?? ''));
        $arrivalIdFilter = trim((string) ($this->option('arrival-id') ?? ''));

        $inPortOnly = (bool) $this->option('in-port');
        $departedOnly = (bool) $this->option('departed');
        $activeCrewOnly = (bool) $this->option('active-only');

        $dateParam = $this->option('date');
        $notesParam = (string) ($this->option('notes') ?: 'Manual checkout via vms:check-vessel-crew');

        if ($dateParam) {
            try {
                $actionTime = Carbon::parse($dateParam);
            } catch (\Throwable $e) {
                $this->error("Invalid date format: [{$dateParam}]. Please use YYYY-MM-DD or YYYY-MM-DD HH:mm:ss.");

                return 1;
            }
        } else {
            $actionTime = now();
        }

        $this->info('========================================================================');
        $this->info('  VMS Vessel Arrival & Crew Inspection / Manual Checkout');
        $this->info('  Mode: '.($dryRun ? 'DRY-RUN (Simulation, database will NOT be modified)' : 'LIVE EXECUTION'));
        $this->info("  Timestamp Reference: {$actionTime->toDateTimeString()}");
        $this->info('========================================================================');

        // Build Query
        $query = VesselArrival::with(['vessel', 'crew' => function ($q) {
            $q->orderBy('vessel_arrival_crew.status', 'asc')
                ->orderBy('visitors.name', 'asc');
        }]);

        if ($arrivalIdFilter !== '') {
            $query->where('vessel_arrivals.id', $arrivalIdFilter);
        }

        if ($imoFilter !== '') {
            $query->whereHas('vessel', function (Builder $q) use ($imoFilter) {
                $q->where('code', 'like', "%{$imoFilter}%");
            });
        }

        if ($nameFilter !== '') {
            $query->where(function (Builder $q) use ($nameFilter) {
                $q->where('vessel_name', 'like', "%{$nameFilter}%")
                    ->orWhereHas('vessel', fn (Builder $vq) => $vq->where('name', 'like', "%{$nameFilter}%"));
            });
        }

        if ($searchTerm !== '') {
            $query->where(function (Builder $q) use ($searchTerm) {
                $q->where('vessel_name', 'like', "%{$searchTerm}%")
                    ->orWhere('id', 'like', "%{$searchTerm}%")
                    ->orWhereHas('vessel', function (Builder $vq) use ($searchTerm) {
                        $vq->where('code', 'like', "%{$searchTerm}%")
                            ->orWhere('name', 'like', "%{$searchTerm}%");
                    });
            });
        }

        if ($inPortOnly) {
            $query->whereNull('sign_out_date');
        } elseif ($departedOnly) {
            $query->whereNotNull('sign_out_date');
        }

        if ($activeCrewOnly) {
            $query->whereHas('crew', function (Builder $q) {
                $q->where('vessel_arrival_crew.status', 'checked_in');
            });
        }

        $arrivals = $query->orderBy('sign_in_date', 'desc')
            ->orderBy('vessel_name', 'asc')
            ->get();

        if ($arrivals->isEmpty()) {
            $this->warn('No vessel arrivals found matching the given criteria.');

            return 0;
        }

        $this->info("Found {$arrivals->count()} matching vessel arrival(s):\n");

        $tableRows = [];
        $totalActiveCrew = 0;
        $totalCheckedOutCrew = 0;
        $totalExpectedCrew = 0;
        $vesselsInPortCount = 0;

        foreach ($arrivals as $idx => $arrival) {
            $imo = $arrival->vessel?->code ?? '-';
            $isInPort = $arrival->sign_out_date === null;
            if ($isInPort) {
                $vesselsInPortCount++;
            }

            $crewList = $arrival->crew;
            $activeCrew = $crewList->where('pivot.status', 'checked_in')->count();
            $checkedOut = $crewList->where('pivot.status', 'checked_out')->count();
            $expected = $crewList->where('pivot.status', 'expected')->count();

            $totalActiveCrew += $activeCrew;
            $totalCheckedOutCrew += $checkedOut;
            $totalExpectedCrew += $expected;

            $statusLabel = $isInPort ? 'IN PORT' : 'DEPARTED';

            $tableRows[] = [
                $idx + 1,
                $arrival->vessel_name,
                $imo,
                $arrival->sign_in_date ? Carbon::parse($arrival->sign_in_date)->format('Y-m-d') : '-',
                $arrival->sign_out_date ? Carbon::parse($arrival->sign_out_date)->format('Y-m-d') : '-',
                $statusLabel,
                $crewList->count(),
                $activeCrew,
                $checkedOut,
                $arrival->id,
            ];
        }

        $this->table(
            ['#', 'Vessel Name', 'IMO / Code', 'Sign-In', 'Sign-Out', 'Stay Status', 'Total Crew', 'Active (In)', 'Out', 'Arrival UUID'],
            $tableRows
        );

        foreach ($arrivals as $idx => $arrival) {
            $imo = $arrival->vessel?->code ?? '-';
            $isInPort = $arrival->sign_out_date === null;
            $statusStr = $isInPort ? 'IN PORT' : 'DEPARTED';
            $activeCrew = $arrival->crew->where('pivot.status', 'checked_in')->count();
            $this->line('  [#'.($idx + 1)."] {$arrival->vessel_name} | IMO: {$imo} | Status: {$statusStr} | Crew: {$activeCrew} active / {$arrival->crew->count()} total");
        }

        $this->line("<comment>Summary:</comment> {$arrivals->count()} vessels ({$vesselsInPortCount} in port) | {$totalActiveCrew} active crew, {$totalCheckedOutCrew} checked-out, {$totalExpectedCrew} expected.");

        // Detailed Crew Roster if --detail requested
        if ($showDetail) {
            $this->newLine();
            $this->info('--- DETAILED CREW ROSTER ---');

            foreach ($arrivals as $arrival) {
                $imo = $arrival->vessel?->code ?? '-';
                $this->line("\n<fg=cyan>Vessel: {$arrival->vessel_name}</fg=cyan> (IMO: {$imo}) [Arrival: {$arrival->id}]");

                if ($arrival->crew->isEmpty()) {
                    $this->line('  <comment>(No crew members attached)</comment>');

                    continue;
                }

                $crewRows = [];
                foreach ($arrival->crew as $cIdx => $crew) {
                    $pivot = $crew->pivot;
                    $crewStatus = strtoupper((string) $pivot->status);

                    $this->line("    • {$crew->name} ({$crew->visitor_no}) - [{$crewStatus}] - Identity: {$crew->id_type} {$crew->id_number}");

                    $crewRows[] = [
                        $cIdx + 1,
                        $crew->visitor_no ?? '-',
                        $crew->name,
                        $crew->id_type.' '.$crew->id_number,
                        $crewStatus,
                        $pivot->checked_in_at ? Carbon::parse($pivot->checked_in_at)->toDateTimeString() : '-',
                        $pivot->checked_out_at ? Carbon::parse($pivot->checked_out_at)->toDateTimeString() : '-',
                        $crew->company ?? '-',
                    ];
                }

                $this->table(
                    ['#', 'Visitor No', 'Name', 'Identity', 'Crew Status', 'Checked-In At', 'Checked-Out At', 'Company'],
                    $crewRows
                );
            }
        }

        // Handle Checkout Operations
        if (! $doCheckoutCrew && ! $doCheckoutVessel) {
            $this->newLine();
            $this->line('<info>Tip:</info> To check out active crew on these vessels, use <comment>--checkout-crew</comment>.');
            $this->line('<info>Tip:</info> To sign out (depart) vessels still in port, use <comment>--checkout-vessel</comment>.');
            $this->line('<info>Tip:</info> Combine with <comment>--dry-run</comment> to preview without persisting changes.');

            return 0;
        }

        $this->newLine();
        $this->info('========================================================================');
        $this->info('  ACTIONS TO EXECUTE');
        $this->info('========================================================================');

        $activeCrewToCheckout = [];
        $vesselsToDepart = [];

        foreach ($arrivals as $arrival) {
            if ($doCheckoutCrew) {
                $activeCrewIds = $arrival->crew
                    ->where('pivot.status', 'checked_in')
                    ->pluck('id')
                    ->all();

                if (! empty($activeCrewIds)) {
                    $activeCrewToCheckout[$arrival->id] = [
                        'arrival' => $arrival,
                        'crew_ids' => $activeCrewIds,
                    ];
                }
            }

            if ($doCheckoutVessel && $arrival->sign_out_date === null) {
                $vesselsToDepart[] = $arrival;
            }
        }

        $totalCrewTargets = array_sum(array_map(fn ($item) => count($item['crew_ids']), $activeCrewToCheckout));
        $totalVesselTargets = count($vesselsToDepart);

        $this->line("Target Crew to Check Out : <fg=yellow>{$totalCrewTargets}</> crew member(s) across ".count($activeCrewToCheckout).' vessel(s)');
        $this->line("Target Vessels to Sign Out : <fg=yellow>{$totalVesselTargets}</> vessel(s)");

        if ($totalCrewTargets === 0 && $totalVesselTargets === 0) {
            $this->warn('No targets qualify for checkout / sign-out based on matching criteria.');

            return 0;
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('[DRY-RUN] Simulation completed. No changes were made to the database.');

            return 0;
        }

        if (! $force) {
            $confirmed = $this->confirm(
                "Are you sure you want to proceed with checking out {$totalCrewTargets} crew member(s) and signing out {$totalVesselTargets} vessel(s)?",
                false
            );

            if (! $confirmed) {
                $this->warn('Operation cancelled by user.');

                return 0;
            }
        }

        // Live Execution
        $crewCheckoutSuccess = 0;
        $vesselSignOutSuccess = 0;

        // 1. Checkout active crew
        foreach ($activeCrewToCheckout as $item) {
            /** @var VesselArrival $arrival */
            $arrival = $item['arrival'];
            $crewIds = $item['crew_ids'];

            $crewService->checkOut(
                $arrival,
                $crewIds,
                $actionTime->toDateTimeString(),
                $notesParam,
                null // Console System Execution
            );

            $crewCheckoutSuccess += count($crewIds);
            $this->line('  ✓ Checked out <info>'.count($crewIds)."</info> crew from <comment>{$arrival->vessel_name}</comment>");
        }

        // 2. Sign out vessels
        foreach ($vesselsToDepart as $arrival) {
            $arrival->update([
                'sign_out_date' => $actionTime->toDateString(),
            ]);

            ActivityLogger::logEvent('vessel_arrival_manual_sign_out', [
                'vessel_arrival_id' => $arrival->id,
                'vessel_name' => $arrival->vessel_name,
                'sign_out_date' => $actionTime->toDateString(),
                'notes' => $notesParam,
            ]);

            $vesselSignOutSuccess++;
            $this->line("  ✓ Signed out vessel <info>{$arrival->vessel_name}</info> (sign_out_date: {$actionTime->toDateString()})");
        }

        $this->newLine();
        $this->info("Successfully processed: {$crewCheckoutSuccess} crew member(s) checked out, {$vesselSignOutSuccess} vessel(s) signed out.");

        return 0;
    }
}
