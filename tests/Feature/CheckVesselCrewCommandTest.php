<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\VesselArrival;
use App\Models\VesselArrivalCrew;
use App\Models\VesselArrivalCrewLog;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckVesselCrewCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_searches_vessels_by_name_and_imo(): void
    {
        $vessel = Location::factory()->create([
            'name' => 'KM SAMUDRA JAYA',
            'code' => '9123456',
        ]);

        $arrival = VesselArrival::factory()->create([
            'location_id' => $vessel->id,
            'vessel_name' => 'KM SAMUDRA JAYA',
            'sign_in_date' => '2026-10-01',
            'sign_out_date' => null,
        ]);

        $crew1 = Visitor::factory()->crew($vessel)->create(['name' => 'Joko Pelaut']);
        $crew2 = Visitor::factory()->crew($vessel)->create(['name' => 'Bambang Kelana']);

        VesselArrivalCrew::create([
            'id' => (string) Str::uuid7(),
            'vessel_arrival_id' => $arrival->id,
            'visitor_id' => $crew1->id,
            'status' => 'checked_in',
            'checked_in_at' => now(),
            'checked_out_at' => null,
        ]);

        VesselArrivalCrew::create([
            'id' => (string) Str::uuid7(),
            'vessel_arrival_id' => $arrival->id,
            'visitor_id' => $crew2->id,
            'status' => 'checked_out',
            'checked_in_at' => now()->subDays(2),
            'checked_out_at' => now()->subDay(),
        ]);

        $exitCode = Artisan::call('vms:check-vessel-crew', ['--imo' => '9123456']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('KM SAMUDRA JAYA');
        expect($output)->toContain('9123456');
        expect($output)->toContain('IN PORT');
    }

    public function test_it_displays_detailed_crew_roster(): void
    {
        $vessel = Location::factory()->create([
            'name' => 'KM BINTANG TIMUR',
            'code' => '9888777',
        ]);

        $arrival = VesselArrival::factory()->create([
            'location_id' => $vessel->id,
            'vessel_name' => 'KM BINTANG TIMUR',
            'sign_in_date' => '2026-10-10',
            'sign_out_date' => null,
        ]);

        $crew = Visitor::factory()->crew($vessel)->create([
            'name' => 'Surya Gemilang',
            'visitor_no' => 'CRW-2026-0099',
        ]);

        VesselArrivalCrew::create([
            'id' => (string) Str::uuid7(),
            'vessel_arrival_id' => $arrival->id,
            'visitor_id' => $crew->id,
            'status' => 'checked_in',
            'checked_in_at' => now(),
            'checked_out_at' => null,
        ]);

        $exitCode = Artisan::call('vms:check-vessel-crew', [
            'search' => 'KM BINTANG TIMUR',
            '--detail' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('DETAILED CREW ROSTER');
        expect($output)->toContain('Surya Gemilang');
        expect($output)->toContain('CHECKED_IN');
    }

    public function test_it_simulates_checkout_in_dry_run_mode(): void
    {
        $vessel = Location::factory()->create([
            'name' => 'KM ANUGERAH BAHARI',
            'code' => '9555111',
        ]);

        $arrival = VesselArrival::factory()->create([
            'location_id' => $vessel->id,
            'vessel_name' => 'KM ANUGERAH BAHARI',
            'sign_in_date' => '2026-10-05',
            'sign_out_date' => null,
        ]);

        $crew = Visitor::factory()->crew($vessel)->create(['name' => 'Andi Wijaya']);

        $pivot = VesselArrivalCrew::create([
            'id' => (string) Str::uuid7(),
            'vessel_arrival_id' => $arrival->id,
            'visitor_id' => $crew->id,
            'status' => 'checked_in',
            'checked_in_at' => now(),
            'checked_out_at' => null,
        ]);

        $this->artisan('vms:check-vessel-crew --imo=9555111 --checkout-crew --checkout-vessel --dry-run')
            ->assertExitCode(0)
            ->expectsOutputToContain('DRY-RUN (Simulation, database will NOT be modified)')
            ->expectsOutputToContain('[DRY-RUN] Simulation completed');

        // Verify database is untouched
        $pivot->refresh();
        expect($pivot->status)->toBe('checked_in');

        $arrival->refresh();
        expect($arrival->sign_out_date)->toBeNull();
    }

    public function test_it_executes_live_manual_checkout_with_force_flag(): void
    {
        $vessel = Location::factory()->create([
            'name' => 'KM NUSANTARA MEGAH',
            'code' => '9666222',
        ]);

        $arrival = VesselArrival::factory()->create([
            'location_id' => $vessel->id,
            'vessel_name' => 'KM NUSANTARA MEGAH',
            'sign_in_date' => '2026-10-01',
            'sign_out_date' => null,
        ]);

        $crew = Visitor::factory()->crew($vessel)->create(['name' => 'Hendro Santoso']);

        $pivot = VesselArrivalCrew::create([
            'id' => (string) Str::uuid7(),
            'vessel_arrival_id' => $arrival->id,
            'visitor_id' => $crew->id,
            'status' => 'checked_in',
            'checked_in_at' => now()->subDays(3),
            'checked_out_at' => null,
        ]);

        $this->artisan('vms:check-vessel-crew --imo=9666222 --checkout-crew --checkout-vessel --force --notes="Manual check out test"')
            ->assertExitCode(0)
            ->expectsOutputToContain('Checked out 1 crew from KM NUSANTARA MEGAH')
            ->expectsOutputToContain('Signed out vessel KM NUSANTARA MEGAH');

        // Verify pivot is checked_out
        $pivot->refresh();
        expect($pivot->status)->toBe('checked_out');
        expect($pivot->checked_out_at)->not->toBeNull();

        // Verify crew log was recorded
        expect(VesselArrivalCrewLog::where('vessel_arrival_id', $arrival->id)
            ->where('visitor_id', $crew->id)
            ->where('action', 'check_out')
            ->exists()
        )->toBeTrue();

        // Verify vessel sign_out_date is set
        $arrival->refresh();
        expect($arrival->sign_out_date)->not->toBeNull();
    }

    public function test_it_filters_by_in_port_and_active_only(): void
    {
        $vesselInPort = Location::factory()->create(['name' => 'KM IN PORT', 'code' => '9111001']);
        $vesselDeparted = Location::factory()->create(['name' => 'KM DEPARTED', 'code' => '9111002']);

        $arrivalInPort = VesselArrival::factory()->create([
            'location_id' => $vesselInPort->id,
            'vessel_name' => 'KM IN PORT',
            'sign_in_date' => '2026-10-15',
            'sign_out_date' => null,
        ]);

        $arrivalDeparted = VesselArrival::factory()->create([
            'location_id' => $vesselDeparted->id,
            'vessel_name' => 'KM DEPARTED',
            'sign_in_date' => '2026-09-01',
            'sign_out_date' => '2026-09-10',
        ]);

        $crewActive = Visitor::factory()->crew($vesselInPort)->create(['name' => 'Active Crew']);
        VesselArrivalCrew::create([
            'id' => (string) Str::uuid7(),
            'vessel_arrival_id' => $arrivalInPort->id,
            'visitor_id' => $crewActive->id,
            'status' => 'checked_in',
            'checked_in_at' => now(),
            'checked_out_at' => null,
        ]);

        // Filter: --in-port --active-only
        $this->artisan('vms:check-vessel-crew --in-port --active-only')
            ->assertExitCode(0)
            ->expectsOutputToContain('KM IN PORT')
            ->doesntExpectOutputToContain('KM DEPARTED');
    }
}
