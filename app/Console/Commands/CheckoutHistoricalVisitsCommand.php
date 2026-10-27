<?php

namespace App\Console\Commands;

use App\Models\VehicleCard;
use App\Models\Visit;
use App\Models\VisitorCard;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckoutHistoricalVisitsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vms:checkout-historical-visits
                            {--before= : Checkout visits on or before this check-in date (YYYY-MM-DD). Default is yesterday.}
                            {--time=17:00:00 : Time of departure to assign (HH:mm:ss). Default is 17:00:00.}
                            {--dry-run : Simulate checkout without modifying the database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Bulk checkout open historical visits (e.g. imported data) up to a specified past date while safely preserving today\'s active check-ins';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $timeParam = (string) ($this->option('time') ?: '17:00:00');
        $beforeInput = $this->option('before');

        $today = Carbon::today();
        $yesterday = Carbon::yesterday();

        if ($beforeInput) {
            try {
                $beforeDate = Carbon::parse($beforeInput)->startOfDay();
            } catch (\Throwable $e) {
                $this->error("Invalid date format for --before: [{$beforeInput}]. Please use YYYY-MM-DD.");

                return 1;
            }
        } else {
            $beforeDate = $yesterday;
        }

        // Strict Safety Guard: Never allow checking out today or future visits with this command
        if ($beforeDate->greaterThanOrEqualTo($today)) {
            $this->error("Safety Error: Cannot checkout visits from today ({$today->toDateString()}) or future dates.");
            $this->error('This command is strictly for historical / imported open visits. Visits from today must be checked out through the standard counter UI.');

            return 1;
        }

        $cutoffDateString = $beforeDate->toDateString();

        $this->info('====================================================');
        $this->info('  VMS Historical Visits Bulk Checkout');
        $this->info('  Mode: '.($dryRun ? 'DRY-RUN (Simulation, database will NOT be modified)' : 'LIVE EXECUTION (Persisting changes)'));
        $this->info("  Check-In Date Cutoff: <= {$cutoffDateString} (Strictly excludes today: {$today->toDateString()})");
        $this->info("  Assigned Checkout Time: {$timeParam}");
        $this->info('====================================================');

        // Query open visits up to the cutoff date
        $baseQuery = Visit::query()
            ->where(function ($q) {
                $q->whereNull('checked_out_at')
                    ->orWhereNull('time_out');
            })
            ->where('date', '<=', $cutoffDateString)
            ->where('date', '<', $today->toDateString());

        $totalOpen = (clone $baseQuery)->count();

        if ($totalOpen === 0) {
            $this->info("No open historical visits found on or before {$cutoffDateString}. Everything is up to date.");

            return 0;
        }

        $this->info("Found {$totalOpen} open historical visits matching criteria.");

        // Show a few samples
        $sampleVisits = (clone $baseQuery)
            ->with(['visitor', 'visitorCards', 'vehicleCard'])
            ->orderBy('date', 'desc')
            ->take(5)
            ->get();

        $this->line('Sample visits to be checked out:');
        foreach ($sampleVisits as $sv) {
            $visitorName = $sv->visitor?->name ?? 'Unknown';
            $ticket = $sv->ticket_number ?? $sv->legacy_registration_no ?? $sv->id;
            $this->line("  -> Ticket: <comment>{$ticket}</comment> | Name: {$visitorName} | Check-in Date: <info>{$sv->date}</info> | Time In: {$sv->time_in}");
        }

        if ($dryRun) {
            $this->warn("\n[SIMULATION COMPLETE] {$totalOpen} visits would be checked out and their cards released to 'available'. No records were modified.");

            return 0;
        }

        // Live execution in batches
        $this->info("\nProcessing checkouts in batches of 250...");
        $processedCount = 0;
        $bar = $this->output->createProgressBar($totalOpen);
        $bar->start();

        // Process IDs in chunks to prevent memory bloat and long table locks
        $ids = (clone $baseQuery)->pluck('id');

        foreach ($ids->chunk(250) as $chunkIds) {
            DB::transaction(function () use ($chunkIds, $timeParam, &$processedCount, $bar) {
                $visits = Visit::with(['visitorCards'])
                    ->whereIn('id', $chunkIds)
                    ->lockForUpdate()
                    ->get();

                $visitorCardIdsToRelease = [];
                $vehicleCardIdsToRelease = [];

                foreach ($visits as $visit) {
                    if ($visit->checked_out_at !== null && $visit->time_out !== null) {
                        continue;
                    }

                    $checkoutDate = $visit->date ?: Carbon::yesterday()->toDateString();
                    $checkoutDateTime = Carbon::parse($checkoutDate.' '.$timeParam);

                    $visit->update([
                        'checked_out_at' => $checkoutDateTime,
                        'time_out' => $checkoutDateTime->toTimeString(),
                        'checked_out_by_name' => 'System Historical Checkout',
                    ]);

                    foreach ($visit->visitorCards as $card) {
                        $visitorCardIdsToRelease[] = $card->id;
                    }

                    if ($visit->vehicle_card_id) {
                        $vehicleCardIdsToRelease[] = $visit->vehicle_card_id;
                    }

                    $processedCount++;
                    $bar->advance();
                }

                // Release physical badges back to inventory
                if (! empty($visitorCardIdsToRelease)) {
                    VisitorCard::whereIn('id', array_values(array_unique($visitorCardIdsToRelease)))
                        ->update(['status' => 'available']);
                }

                if (! empty($vehicleCardIdsToRelease)) {
                    VehicleCard::whereIn('id', array_values(array_unique($vehicleCardIdsToRelease)))
                        ->update(['status' => 'available']);
                }
            });
        }

        $bar->finish();
        $this->info("\n\n====================================================");
        $this->info("Successfully checked out {$processedCount} historical visits!");
        $this->info("All associated visitor and vehicle cards have been returned to 'available'.");
        $this->info('Today\'s active check-ins were strictly protected and left untouched.');
        $this->info('====================================================');

        return 0;
    }
}
