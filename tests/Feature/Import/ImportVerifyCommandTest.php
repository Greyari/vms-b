<?php

namespace Tests\Feature\Import;

use App\Models\Location;
use App\Models\Nationality;
use App\Models\Staff;
use App\Models\VesselArrival;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImportVerifyCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        $this->tempDir = storage_path('framework/testing/vms_verify_'.uniqid());
        File::makeDirectory($this->tempDir, 0755, true);

        // Seed valid baseline imported data
        $nat = Nationality::forceCreate(['id' => (string) Str::uuid7(), 'name' => 'INDONESIA']);
        $loc = Location::forceCreate(['id' => (string) Str::uuid7(), 'code' => '9123456', 'name' => 'MV OCEANIC', 'type' => 'repair_vessel']);
        $staff = Staff::forceCreate(['id' => (string) Str::uuid7(), 'staff_id' => 'ST-01', 'name' => 'Staff 1', 'is_active' => true]);

        $cardInUse = VisitorCard::forceCreate(['id' => (string) Str::uuid7(), 'card_no' => 'C-INUSE', 'status' => 'in_use', 'card_type' => 'visitor']);
        $cardAvail = VisitorCard::forceCreate(['id' => (string) Str::uuid7(), 'card_no' => 'C-AVAIL', 'status' => 'available', 'card_type' => 'visitor']);

        $vis1 = Visitor::forceCreate(['id' => (string) Str::uuid7(), 'visitor_no' => 101, 'id_type' => 'ktp', 'id_number' => '12345', 'name' => 'Vis 1', 'visitor_type' => 'visitor']);
        $vis2 = Visitor::forceCreate(['id' => (string) Str::uuid7(), 'visitor_no' => 102, 'id_type' => 'ktp', 'id_number' => '67890', 'name' => 'Vis 2', 'visitor_type' => 'crew']);

        // 1 open visit (cardInUse)
        $openVisit = Visit::forceCreate([
            'id' => (string) Str::uuid7(),
            'visitor_id' => $vis1->id,
            'staff_id' => $staff->id,
            'location_id' => $loc->id,
            'date' => '2026-05-01',
            'time_in' => '08:00',
            'checked_out_at' => null,
            'legacy_registration_no' => 'REG-001',
        ]);
        DB::table('visit_visitor_card')->insert([
            'id' => (string) Str::uuid7(),
            'visit_id' => $openVisit->id,
            'visitor_card_id' => $cardInUse->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1 closed visit (no card or closed card)
        $closedVisit = Visit::forceCreate([
            'id' => (string) Str::uuid7(),
            'visitor_id' => $vis2->id,
            'staff_id' => $staff->id,
            'location_id' => $loc->id,
            'date' => '2026-05-01',
            'time_in' => '08:00',
            'time_out' => '17:00',
            'checked_out_at' => '2026-05-01 17:00:00',
            'legacy_registration_no' => 'REG-002',
        ]);

        // 1 vessel arrival with 1 crew
        $arrival = VesselArrival::forceCreate([
            'id' => (string) Str::uuid7(),
            'location_id' => $loc->id,
            'vessel_name' => 'MV OCEANIC',
            'sign_in_date' => '2026-05-01',
            'sign_out_date' => '2026-05-10',
        ]);
        DB::table('vessel_arrival_crew')->insert([
            'id' => (string) Str::uuid7(),
            'vessel_arrival_id' => $arrival->id,
            'visitor_id' => $vis2->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Prepare manifest matching this database
        $manifest = [
            'source' => 'vms-old',
            'exported_at' => now()->toIso8601String(),
            'tables' => [
                'Nationality' => ['row_count' => 1],
                'ESSVesselMaster' => ['row_count' => 1],
                'ESSStaffToVisit' => ['row_count' => 1],
                'ESSVisitorCardMaster' => ['row_count' => 2],
                'ESSVisitorMaster' => ['row_count' => 2],
                'ESSVisitorRegistration' => ['row_count' => 2],
                'ESSVesselTransaction' => ['row_count' => 1],
                'ESSCrewTransaction' => ['row_count' => 1],
            ],
        ];
        File::put($this->tempDir.'/manifest.json', json_encode($manifest));

        // Create sample CSVs with matching count for Check 3
        File::put($this->tempDir.'/ESSVisitorRegistration.csv',
            "VisitorID,CheckOutTime\n".
            "101,0\n".
            "102,17.0\n"
        );
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_passes_all_checks_on_valid_data(): void
    {
        $this->artisan('vms:import-verify', [
            '--source' => 'vms-old',
            '--path' => $this->tempDir,
        ])->expectsOutputToContain('All 7 integrity checks PASSED.')
            ->assertExitCode(0);
    }

    public function test_fails_and_returns_exit_code_1_when_card_status_is_corrupted(): void
    {
        // Corrupt card: card on open visit is set to available
        VisitorCard::where('card_no', 'C-INUSE')->update(['status' => 'available']);

        $this->artisan('vms:import-verify', [
            '--source' => 'vms-old',
            '--path' => $this->tempDir,
        ])->expectsOutputToContain('Check 5 (Card Status Integrity) FAILED')
            ->assertExitCode(1);
    }

    public function test_fails_and_returns_exit_code_1_when_visitor_no_is_null(): void
    {
        // Corrupt visitor_no to NULL
        Visitor::where('visitor_no', 101)->update(['visitor_no' => null]);

        $this->artisan('vms:import-verify', [
            '--source' => 'vms-old',
            '--path' => $this->tempDir,
        ])->expectsOutputToContain('Check 6 (Enum & Foreign Key Integrity) FAILED')
            ->assertExitCode(1);
    }
}
