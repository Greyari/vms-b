<?php

namespace Tests\Feature\Import;

use App\Enums\LocationType;
use App\Models\Location;
use App\Models\VesselArrival;
use App\Models\Visitor;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImportArrivalCrewCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tempFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        $this->tempFile = storage_path('framework/testing/test_arrival_'.uniqid().'.csv');
    }

    protected function tearDown(): void
    {
        if (File::exists($this->tempFile)) {
            File::delete($this->tempFile);
        }
        parent::tearDown();
    }

    public function test_validates_missing_file(): void
    {
        $this->artisan('vms:import-arrival-crew', ['file' => 'non_existent_file.csv'])
            ->expectsOutputToContain('File not found')
            ->assertExitCode(1);
    }

    public function test_dry_run_does_not_persist_records(): void
    {
        $loc = Location::create([
            'id' => (string) Str::uuid7(),
            'code' => '9419670',
            'name' => 'MT. PIS PATRIOT',
            'type' => LocationType::RepairVessel,
        ]);

        $visitor = Visitor::create([
            'id' => (string) Str::uuid7(),
            'visitor_no' => 358024,
            'name' => 'SAPUTRA HIDAYAT',
            'id_type' => 'ktp',
            'id_number' => '1407020809960002',
        ]);

        $csvContent = "358024,SAPUTRA HIDAYAT,K,1407020809960002,MT. PIS PATRIOT,9419670,MT. PIS PATRIOT,0,2026-09-26 11:27:12,11.45,0.00,CHANDRASEKARAN,MT. PIS PATRIOT,,NULL,NULL,2026-10-21 19:25:25,12,2026-10-21 19:25:25,100\n";
        File::put($this->tempFile, $csvContent);

        $this->artisan('vms:import-arrival-crew', [
            'file' => $this->tempFile,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('DRY RUN COMPLETE')
            ->assertExitCode(0);

        $this->assertEquals(0, VesselArrival::count());
        $this->assertEquals(0, DB::table('vessel_arrival_crew')->count());
    }

    public function test_live_execution_imports_vessel_and_crew(): void
    {
        $loc = Location::create([
            'id' => (string) Str::uuid7(),
            'code' => '9419670',
            'name' => 'MT. PIS PATRIOT',
            'type' => LocationType::RepairVessel,
        ]);

        $visitor = Visitor::create([
            'id' => (string) Str::uuid7(),
            'visitor_no' => 358024,
            'name' => 'SAPUTRA HIDAYAT',
            'id_type' => 'ktp',
            'id_number' => '1407020809960002',
        ]);

        $csvContent = "358024,SAPUTRA HIDAYAT,K,1407020809960002,MT. PIS PATRIOT,9419670,MT. PIS PATRIOT,0,2026-09-26 11:27:12,11.45,0.00,CHANDRASEKARAN,MT. PIS PATRIOT,,NULL,NULL,2026-10-21 19:25:25,12,2026-10-21 19:25:25,100\n";
        File::put($this->tempFile, $csvContent);

        $this->artisan('vms:import-arrival-crew', [
            'file' => $this->tempFile,
        ])
            ->expectsOutputToContain('IMPORT COMPLETED')
            ->assertExitCode(0);

        $this->assertEquals(1, VesselArrival::count());
        $arrival = VesselArrival::first();
        $this->assertEquals($loc->id, $arrival->location_id);
        $this->assertEquals('2026-09-26', $arrival->sign_in_date->format('Y-m-d'));

        $crewPivot = DB::table('vessel_arrival_crew')->where('vessel_arrival_id', $arrival->id)->first();
        $this->assertNotNull($crewPivot);
        $this->assertEquals($visitor->id, $crewPivot->visitor_id);
        $this->assertEquals('checked_in', $crewPivot->status);

        $this->assertDatabaseHas('vessel_arrival_crew_logs', [
            'vessel_arrival_id' => $arrival->id,
            'visitor_id' => $visitor->id,
            'action' => 'check_in',
        ]);
    }
}
