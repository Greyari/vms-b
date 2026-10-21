<?php

namespace App\Console\Commands;

use App\Enums\CardType;
use App\Enums\LocationType;
use App\Enums\VisitorType;
use App\Models\Location;
use App\Models\Nationality;
use App\Models\Staff;
use App\Models\VesselArrival;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ImportVerifyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vms:import-verify
                            {--source=vms-old : Source dataset (vms-old or pilot)}
                            {--path= : Path to data folder}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify database integrity after VMS-old or Pilot dataset import';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $source = $this->option('source') ?: 'vms-old';
        $path = $this->option('path') ?: storage_path('app/vms-exports/'.$source);

        $this->info("Verifying import integrity for source [{$source}] at path [{$path}]...");

        $allPassed = true;

        // Check 1: Entity Row Counts
        if (! $this->verifyEntityRowCounts($source, $path)) {
            $allPassed = false;
        }

        // Check 2: Monthly Visit Consistency
        if (! $this->verifyMonthlyVisitConsistency($source, $path)) {
            $allPassed = false;
        }

        // Check 3: Open Visits Integrity
        if (! $this->verifyOpenVisits($source, $path)) {
            $allPassed = false;
        }

        // Check 4: Report & Relation Parity
        if (! $this->verifyReportAndRelationParity()) {
            $allPassed = false;
        }

        // Check 5: Card Status Integrity
        if (! $this->verifyCardStatusIntegrity()) {
            $allPassed = false;
        }

        // Check 6: Enum & Foreign Key Integrity
        if (! $this->verifyEnumAndForeignKeyIntegrity()) {
            $allPassed = false;
        }

        // Check 7: Vessel Arrival Integrity
        if (! $this->verifyVesselArrivalIntegrity($source, $path)) {
            $allPassed = false;
        }

        $this->newLine();
        if ($allPassed) {
            $this->info('All 7 integrity checks PASSED.');

            return 0;
        }

        $this->error('One or more integrity checks FAILED.');

        return 1;
    }

    /**
     * Check 1: Entity Row Counts vs Manifest
     */
    protected function verifyEntityRowCounts(string $source, string $path): bool
    {
        $manifestPath = $path.DIRECTORY_SEPARATOR.'manifest.json';
        if (! File::exists($manifestPath)) {
            $this->error("Check 1 (Entity Row Counts) FAILED: manifest.json not found in [{$path}].");

            return false;
        }

        // manifest.json dari ekspor PowerShell memakai UTF-8 BOM; buang agar JSON terbaca.
        $manifest = json_decode(ltrim(File::get($manifestPath), "\xEF\xBB\xBF"), true);
        if (! isset($manifest['tables']) || ! is_array($manifest['tables'])) {
            $this->error('Check 1 (Entity Row Counts) FAILED: invalid manifest.json format.');

            return false;
        }

        $tableMap = [
            'Nationality' => fn () => Nationality::count(),
            'ESSVesselMaster' => fn () => Location::count(),
            'ESSStaffToVisit' => fn () => Staff::count(),
            'ESSVisitorCardMaster' => fn () => VisitorCard::count(),
            'ESSVisitorMaster' => fn () => Visitor::count(),
            'ESSVisitorRegistration' => fn () => Visit::count(),
            'ESSVesselTransaction' => fn () => VesselArrival::count(),
            'ESSCrewTransaction' => fn () => DB::table('vessel_arrival_crew')->count(),
            'nationalities' => fn () => Nationality::count(),
            'locations' => fn () => Location::count(),
            'staff' => fn () => Staff::count(),
            'staffs' => fn () => Staff::count(), // vms:export-pilot's key
            'cards' => fn () => VisitorCard::count(),
            'visitors' => fn () => Visitor::count(),
            'visits' => fn () => Visit::count(),
            'vessel_arrivals' => fn () => VesselArrival::count(),
            'crew_arrivals' => fn () => DB::table('vessel_arrival_crew')->count(),
        ];

        foreach ($manifest['tables'] as $tableName => $tableMeta) {
            if (isset($tableMap[$tableName])) {
                $expected = $tableMeta['row_count'] ?? 0;
                $actual = ($tableMap[$tableName])();

                if ($expected > 0 && $actual === 0) {
                    $this->error("Check 1 (Entity Row Counts) FAILED: Table {$tableName} has 0 records in DB, expected {$expected}.");

                    return false;
                }
            }
        }

        $this->info('Check 1 (Entity Row Counts) PASSED.');

        return true;
    }

    /**
     * Check 2: Monthly Visit Consistency
     */
    protected function verifyMonthlyVisitConsistency(string $source, string $path): bool
    {
        $nullDateCount = Visit::whereNull('date')->count();
        if ($nullDateCount > 0) {
            $this->error("Check 2 (Monthly Visit Consistency) FAILED: {$nullDateCount} visits have NULL date.");

            return false;
        }

        if (Visit::count() > 0) {
            // One row per calendar month. The expression differs per driver: PostgreSQL has no
            // substr() for a date column, and grouping by a select alias is not portable either
            // (count() drops the alias; SQLite then groups by the string literal "month").
            $month = DB::connection()->getDriverName() === 'pgsql'
                ? "to_char(date, 'YYYY-MM')"
                : 'substr(date, 1, 7)';

            $monthlyCount = DB::query()
                ->fromSub(Visit::query()->selectRaw("{$month} as month")->groupByRaw($month), 'months')
                ->count();

            if ($monthlyCount === 0) {
                $this->error('Check 2 (Monthly Visit Consistency) FAILED: No valid monthly visit groups found.');

                return false;
            }
        }

        $this->info('Check 2 (Monthly Visit Consistency) PASSED.');

        return true;
    }

    /**
     * Check 3: Open Visits Integrity
     */
    protected function verifyOpenVisits(string $source, string $path): bool
    {
        $openInDb = Visit::whereNull('checked_out_at')->count();
        $csvPath = $path.DIRECTORY_SEPARATOR.'ESSVisitorRegistration.csv';

        if (File::exists($csvPath)) {
            $handle = fopen($csvPath, 'r');
            if ($handle !== false) {
                $headers = fgetcsv($handle);
                $checkOutIndex = false;

                if ($headers) {
                    foreach ($headers as $idx => $h) {
                        if (strcasecmp(trim($h), 'CheckOutTime') === 0) {
                            $checkOutIndex = $idx;
                            break;
                        }
                    }
                }

                if ($checkOutIndex !== false) {
                    $openInCsv = 0;
                    while (($row = fgetcsv($handle)) !== false) {
                        $val = trim($row[$checkOutIndex] ?? '');
                        if ($val === '0' || (is_numeric($val) && (float) $val === 0.0)) {
                            $openInCsv++;
                        }
                    }
                    fclose($handle);

                    if ($openInDb !== $openInCsv) {
                        $this->error("Check 3 (Open Visits Integrity) FAILED: DB open visits ({$openInDb}) does not match CSV ({$openInCsv}).");

                        return false;
                    }
                } else {
                    fclose($handle);
                }
            }
        }

        $this->info('Check 3 (Open Visits Integrity) PASSED.');

        return true;
    }

    /**
     * Check 4: Report & Relation Parity
     */
    protected function verifyReportAndRelationParity(): bool
    {
        $orphanVisitorCount = Visit::whereNotIn('visitor_id', Visitor::select('id'))->count();
        $orphanLocationCount = Visit::whereNotNull('location_id')
            ->whereNotIn('location_id', Location::select('id'))
            ->count();
        $orphanStaffCount = Visit::whereNotNull('staff_id')
            ->whereNotIn('staff_id', Staff::select('id'))
            ->count();

        if ($orphanVisitorCount > 0 || $orphanLocationCount > 0 || $orphanStaffCount > 0) {
            $this->error("Check 4 (Report & Relation Parity) FAILED: {$orphanVisitorCount} orphan visitors, {$orphanLocationCount} orphan locations, {$orphanStaffCount} orphan staff in visits.");

            return false;
        }

        $this->info('Check 4 (Report & Relation Parity) PASSED.');

        return true;
    }

    /**
     * Check 5: Card Status Integrity
     */
    protected function verifyCardStatusIntegrity(): bool
    {
        $openVisitCardIds = DB::table('visit_visitor_card')
            ->join('visits', 'visit_visitor_card.visit_id', '=', 'visits.id')
            ->whereNull('visits.checked_out_at')
            ->pluck('visit_visitor_card.visitor_card_id')
            ->unique()
            ->all();

        $inUseWithoutOpenVisit = VisitorCard::where('status', 'in_use')
            ->whereNotIn('id', $openVisitCardIds)
            ->count();

        $availableOnOpenVisit = VisitorCard::where('status', 'available')
            ->whereIn('id', $openVisitCardIds)
            ->count();

        if ($inUseWithoutOpenVisit > 0 || $availableOnOpenVisit > 0) {
            $this->error("Check 5 (Card Status Integrity) FAILED: {$inUseWithoutOpenVisit} in_use cards without open visits, {$availableOnOpenVisit} available cards on open visits.");

            return false;
        }

        $this->info('Check 5 (Card Status Integrity) PASSED.');

        return true;
    }

    /**
     * Check 6: Enum & Foreign Key Integrity
     */
    protected function verifyEnumAndForeignKeyIntegrity(): bool
    {
        $nullVisitorNoCount = Visitor::whereNull('visitor_no')->count();

        $validVisitorTypes = array_map(fn ($case) => $case->value, VisitorType::cases());
        $invalidVisitorTypeCount = Visitor::whereNotIn('visitor_type', $validVisitorTypes)->count();

        $validLocationTypes = array_map(fn ($case) => $case->value, LocationType::cases());
        $invalidLocationTypeCount = Location::whereNotIn('type', $validLocationTypes)->count();

        $validCardTypes = array_map(fn ($case) => $case->value, CardType::cases());
        $invalidCardTypeCount = VisitorCard::whereNotNull('card_type')
            ->whereNotIn('card_type', $validCardTypes)
            ->count();

        if ($nullVisitorNoCount > 0 || $invalidVisitorTypeCount > 0 || $invalidLocationTypeCount > 0 || $invalidCardTypeCount > 0) {
            $this->error("Check 6 (Enum & Foreign Key Integrity) FAILED: {$nullVisitorNoCount} NULL visitor_no, {$invalidVisitorTypeCount} invalid visitor_type, {$invalidLocationTypeCount} invalid location type, {$invalidCardTypeCount} invalid card_type.");

            return false;
        }

        $this->info('Check 6 (Enum & Foreign Key Integrity) PASSED.');

        return true;
    }

    /**
     * Check 7: Vessel Arrival Integrity
     */
    protected function verifyVesselArrivalIntegrity(string $source, string $path): bool
    {
        $orphanArrivalCrew = DB::table('vessel_arrival_crew')
            ->whereNotIn('vessel_arrival_id', VesselArrival::select('id'))
            ->count();

        $orphanVisitorCrew = DB::table('vessel_arrival_crew')
            ->whereNotIn('visitor_id', Visitor::select('id'))
            ->count();

        if ($orphanArrivalCrew > 0 || $orphanVisitorCrew > 0) {
            $this->error("Check 7 (Vessel Arrival Integrity) FAILED: {$orphanArrivalCrew} orphan vessel arrival references, {$orphanVisitorCrew} orphan visitor references.");

            return false;
        }

        $this->info('Check 7 (Vessel Arrival Integrity) PASSED.');

        return true;
    }
}
