<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\VesselArrival;
use App\Models\VesselArrivalCrew;
use App\Models\VesselArrivalCrewLog;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReconcileCrewPlacementsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_modify_database(): void
    {
        $vessel1 = Location::factory()->create(['name' => 'VESSEL ALPHA']);
        $vessel2 = Location::factory()->create(['name' => 'VESSEL BETA']);

        $arrival1 = VesselArrival::factory()->create(['location_id' => $vessel1->id, 'vessel_name' => 'VESSEL ALPHA']);
        $arrival2 = VesselArrival::factory()->create(['location_id' => $vessel2->id, 'vessel_name' => 'VESSEL BETA']);

        $crew = Visitor::factory()->crew($vessel2)->create(['name' => 'Test Sailor']);

        // Historical placement on arrival 1 (checked_out)
        VesselArrivalCrew::create([
            'id' => (string) Str::uuid7(),
            'vessel_arrival_id' => $arrival1->id,
            'visitor_id' => $crew->id,
            'status' => 'checked_out',
            'checked_in_at' => now()->subDays(10),
            'checked_out_at' => now()->subDays(5),
            'notes' => 'Pindah ke VESSEL BETA',
        ]);

        // Active placement on arrival 2 (checked_in)
        VesselArrivalCrew::create([
            'id' => (string) Str::uuid7(),
            'vessel_arrival_id' => $arrival2->id,
            'visitor_id' => $crew->id,
            'status' => 'checked_in',
            'checked_in_at' => now()->subDays(5),
            'checked_out_at' => null,
            'notes' => 'Pindahan dari VESSEL ALPHA',
        ]);

        $this->artisan('vms:reconcile-crew-placements --dry-run')
            ->assertExitCode(0)
            ->expectsOutputToContain('DRY-RUN');

        expect(VesselArrivalCrew::where('visitor_id', $crew->id)->count())->toBe(2);
    }

    public function test_live_execution_removes_obsolete_placement_and_preserves_logs(): void
    {
        $vessel1 = Location::factory()->create(['name' => 'VESSEL ALPHA']);
        $vessel2 = Location::factory()->create(['name' => 'VESSEL BETA']);

        $arrival1 = VesselArrival::factory()->create(['location_id' => $vessel1->id, 'vessel_name' => 'VESSEL ALPHA']);
        $arrival2 = VesselArrival::factory()->create(['location_id' => $vessel2->id, 'vessel_name' => 'VESSEL BETA']);

        $crew = Visitor::factory()->crew($vessel2)->create(['name' => 'Test Sailor']);

        // Historical placement on arrival 1 (checked_out)
        VesselArrivalCrew::create([
            'id' => (string) Str::uuid7(),
            'vessel_arrival_id' => $arrival1->id,
            'visitor_id' => $crew->id,
            'status' => 'checked_out',
            'checked_in_at' => now()->subDays(10),
            'checked_out_at' => now()->subDays(5),
            'notes' => 'Pindah ke VESSEL BETA',
        ]);

        // Active placement on arrival 2 (checked_in)
        VesselArrivalCrew::create([
            'id' => (string) Str::uuid7(),
            'vessel_arrival_id' => $arrival2->id,
            'visitor_id' => $crew->id,
            'status' => 'checked_in',
            'checked_in_at' => now()->subDays(5),
            'checked_out_at' => null,
            'notes' => 'Pindahan dari VESSEL ALPHA',
        ]);

        $this->artisan('vms:reconcile-crew-placements')
            ->assertExitCode(0);

        // Only 1 placement remains in vessel_arrival_crew (the active one on arrival 2)
        expect(VesselArrivalCrew::where('visitor_id', $crew->id)->count())->toBe(1);
        $remaining = VesselArrivalCrew::where('visitor_id', $crew->id)->first();
        expect($remaining->vessel_arrival_id)->toBe($arrival2->id);

        // Audit log exists
        expect(VesselArrivalCrewLog::where('visitor_id', $crew->id)->count())->toBeGreaterThanOrEqual(1);
    }
}
