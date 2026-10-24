<?php

namespace App\Console\Commands;

use App\Enums\ImportSource;
use App\Models\ActivityLog;
use App\Models\VehicleCard;
use App\Models\VisitorCard;
use App\Services\Import\ImportReport;
use App\Services\Import\MappingManager;
use App\Services\Import\PilotImporter;
use App\Services\Import\VmsOldImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vms:import
                            {--source= : Source data to import (vms-old or pilot)}
                            {--path= : Path to data folder containing CSV files and manifest.json}
                            {--dry-run : Simulate import without persisting changes}
                            {--skip-cards : Skip importing visitor cards and generating orphan cards from visits}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import VMS-old or Pilot dataset into PostgreSQL database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $source = $this->option('source');
        if (! in_array($source, ['vms-old', 'pilot'], true)) {
            $this->error('The --source option must be either [vms-old] or [pilot].');

            return 1;
        }

        $path = $this->option('path');
        if (! $path || ! File::isDirectory($path)) {
            $this->error("Directory not found at path: [{$path}].");

            return 1;
        }

        $manifestPath = $path.DIRECTORY_SEPARATOR.'manifest.json';
        if (! File::exists($manifestPath)) {
            $this->error("manifest.json not found in [{$path}].");

            return 1;
        }

        $manifest = json_decode((string) File::get($manifestPath), true);
        $manifestSource = is_array($manifest) ? ($manifest['source'] ?? null) : null;
        if ($manifestSource !== null && $manifestSource !== $source) {
            $this->error("manifest.json in [{$path}] was exported from [{$manifestSource}], not [{$source}].");

            return 1;
        }

        // Decision PE-8: VMS-old belongs to ASL, the IIS pilot to CIS; never import one yard's data into the other.
        $sourceYard = ImportSource::from($source)->yardCode();
        $serverYard = (string) config('vms.sync.yard_code');
        if ($serverYard === '') {
            $this->warn("VMS_YARD_CODE is not set, so this server cannot confirm it is {$sourceYard}. [{$source}] holds {$sourceYard}'s data only.");
        } elseif ($serverYard !== $sourceYard) {
            $this->error("[{$source}] holds {$sourceYard}'s data, but this server is {$serverYard} (VMS_YARD_CODE). Import refused (decision PE-8).");

            return 1;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('Running in DRY-RUN mode. No changes will be written to database.');
        }

        $mappingManager = new MappingManager;
        $report = new ImportReport;

        $this->info("Starting import from [{$source}] at [{$path}]...");

        $skipCards = (bool) $this->option('skip-cards');

        if ($source === 'vms-old') {
            $importer = new VmsOldImporter($mappingManager, $report);
            $importer->setSkipCards($skipCards);
            $importer->import($path, $dryRun);
        } else {
            $importer = new PilotImporter($mappingManager, $report);
            $importer->import($path, $dryRun);
        }

        // Synchronize card status: in_use for cards on open visits, available for all others
        if (! $dryRun && ! $skipCards) {
            $inUseCardIds = DB::table('visit_visitor_card')
                ->join('visits', 'visit_visitor_card.visit_id', '=', 'visits.id')
                ->whereNull('visits.checked_out_at')
                ->distinct()
                ->pluck('visit_visitor_card.visitor_card_id')
                ->all();

            if (! empty($inUseCardIds)) {
                VisitorCard::whereIn('id', $inUseCardIds)->update(['status' => 'in_use']);
            }
            VisitorCard::whereNotIn('id', $inUseCardIds)->update(['status' => 'available']);

            $inUseVehicleCardIds = DB::table('visits')
                ->whereNull('checked_out_at')
                ->whereNotNull('vehicle_card_id')
                ->distinct()
                ->pluck('vehicle_card_id')
                ->all();

            if (! empty($inUseVehicleCardIds)) {
                VehicleCard::whereIn('id', $inUseVehicleCardIds)->update(['status' => 'in_use']);
            }
            VehicleCard::whereNotIn('id', $inUseVehicleCardIds)
                ->where('status', 'in_use')
                ->update(['status' => 'available']);

            // Create single summary activity log
            ActivityLog::create([
                'action' => 'data_import',
                'subject_type' => 'import',
                'subject_id' => $source,
                'subject_label' => "Import {$source}",
                'new_values' => $report->toArray()['summary'],
                'created_at' => now(),
            ]);
        }

        $reportPath = $report->saveJsonReport(ImportSource::from($source));
        $this->info("Import report saved to: [{$reportPath}]");

        $summary = $report->toArray();
        $this->table(
            ['Metric', 'Total'],
            [
                ['Total Read', $summary['summary']['total_read']],
                ['Total Inserted', $summary['summary']['total_inserted']],
                ['Total Updated', $summary['summary']['total_updated']],
                ['Total Skipped', $summary['summary']['total_skipped']],
            ]
        );

        $entityRows = [];
        foreach ($summary['entities'] as $entity => $stats) {
            $entityRows[] = [
                $entity,
                $stats['read'],
                $stats['inserted'],
                $stats['updated'],
                $stats['skipped']['total'],
            ];
        }

        if (! empty($entityRows)) {
            $this->table(['Entity', 'Read', 'Inserted', 'Updated', 'Skipped'], $entityRows);
        }

        $this->info('Import finished successfully.');

        return 0;
    }
}
