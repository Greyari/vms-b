<?php

use App\Enums\LocationType;
use App\Models\Location;
use App\Models\VesselArrival;
use App\Models\VesselArrivalCrewLog;
use App\Models\Visitor;
use Illuminate\Support\Str;

it('reopens a checked-out staying vessel when running consolidate command', function () {
    $tugboat = Location::factory()->create([
        'name' => 'TB. NUSANTARA ONE',
        'type' => LocationType::TugBoat,
    ]);

    $arrival = VesselArrival::factory()->create([
        'location_id' => $tugboat->id,
        'vessel_name' => $tugboat->name,
        'sign_in_date' => '2026-08-01',
        'sign_out_date' => '2026-08-10',
        'sign_out_time' => '10:00:00',
        'sign_out_port' => 'BATAM',
    ]);

    // Dry run does not alter database
    $this->artisan('vms:consolidate-staying-vessels --dry-run')
        ->assertSuccessful();

    $arrival->refresh();
    expect($arrival->sign_out_date)->not->toBeNull();

    // Live run reopens the staying vessel
    $this->artisan('vms:consolidate-staying-vessels')
        ->assertSuccessful();

    $arrival->refresh();
    expect($arrival->sign_out_date)->toBeNull()
        ->and($arrival->sign_out_time)->toBeNull()
        ->and($arrival->sign_out_port)->toBeNull();
});

it('consolidates duplicate arrivals for the same staying vessel and migrates crew and logs', function () {
    $tugboat = Location::factory()->create([
        'name' => 'TB. NUSANTARA JAYA',
        'type' => LocationType::TugBoat,
    ]);

    $crew1 = Visitor::factory()->crew()->create(['name' => 'Crew Alfa']);
    $crew2 = Visitor::factory()->crew()->create(['name' => 'Crew Bravo']);

    // Arrival 1: Older arrival, marked signed out earlier
    $arrival1 = VesselArrival::factory()->create([
        'location_id' => $tugboat->id,
        'vessel_name' => $tugboat->name,
        'sign_in_date' => '2026-05-01',
        'sign_out_date' => '2026-05-30',
        'captain' => 'Capt. Ahmad',
    ]);

    // Crew 1 was on arrival 1 and checked out
    DB::table('vessel_arrival_crew')->insert([
        'id' => (string) Str::uuid(),
        'vessel_arrival_id' => $arrival1->id,
        'visitor_id' => $crew1->id,
        'status' => 'checked_out',
        'checked_in_at' => '2026-05-01 08:00:00',
        'checked_out_at' => '2026-05-30 17:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    VesselArrivalCrewLog::create([
        'vessel_arrival_id' => $arrival1->id,
        'visitor_id' => $crew1->id,
        'action' => 'check_out',
        'logged_at' => '2026-05-30 17:00:00',
        'source' => 'vessel_signon',
        'notes' => 'Log lama di arrival 1',
    ]);

    // Arrival 2: Newer arrival, currently active (user created check-in again)
    $arrival2 = VesselArrival::factory()->create([
        'location_id' => $tugboat->id,
        'vessel_name' => $tugboat->name,
        'sign_in_date' => '2026-06-01',
        'sign_out_date' => null,
        'captain' => 'Capt. Ahmad',
    ]);

    // Crew 1 checked back in, and Crew 2 also joined
    DB::table('vessel_arrival_crew')->insert([
        [
            'id' => (string) Str::uuid(),
            'vessel_arrival_id' => $arrival2->id,
            'visitor_id' => $crew1->id,
            'status' => 'checked_in',
            'checked_in_at' => '2026-06-01 09:00:00',
            'checked_out_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => (string) Str::uuid(),
            'vessel_arrival_id' => $arrival2->id,
            'visitor_id' => $crew2->id,
            'status' => 'checked_in',
            'checked_in_at' => '2026-06-02 10:00:00',
            'checked_out_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    VesselArrivalCrewLog::create([
        'vessel_arrival_id' => $arrival2->id,
        'visitor_id' => $crew2->id,
        'action' => 'check_in',
        'logged_at' => '2026-06-02 10:00:00',
        'source' => 'vessel_signon',
        'notes' => 'Log baru di arrival 2',
    ]);

    // Execute consolidation
    $this->artisan('vms:consolidate-staying-vessels')
        ->assertSuccessful();

    // Exactly 1 arrival remains for this tugboat
    $remainingArrivals = VesselArrival::where('location_id', $tugboat->id)->get();
    expect($remainingArrivals)->toHaveCount(1);

    $masterArrival = $remainingArrivals->first();
    expect($masterArrival->sign_out_date)->toBeNull()
        // Sign-in date preserved from earliest arrival
        ->and($masterArrival->sign_in_date->format('Y-m-d'))->toBe('2026-05-01');

    // Both crew members are linked to the master arrival
    $placements = DB::table('vessel_arrival_crew')
        ->where('vessel_arrival_id', $masterArrival->id)
        ->get();

    expect($placements)->toHaveCount(2);

    $crew1Placement = $placements->firstWhere('visitor_id', $crew1->id);
    expect($crew1Placement->status)->toBe('checked_in');

    $crew2Placement = $placements->firstWhere('visitor_id', $crew2->id);
    expect($crew2Placement->status)->toBe('checked_in');

    // All logs from both arrivals now reference master arrival
    $logs = VesselArrivalCrewLog::where('vessel_arrival_id', $masterArrival->id)->get();
    expect($logs)->toHaveCount(2);
});

it('does not alter regular repair vessels with multiple sign-in and sign-outs', function () {
    $repairVessel = Location::factory()->create([
        'name' => 'MV. EVEREST REPAIR',
        'type' => LocationType::RepairVessel,
    ]);

    $arrival1 = VesselArrival::factory()->create([
        'location_id' => $repairVessel->id,
        'sign_in_date' => '2026-01-01',
        'sign_out_date' => '2026-01-20',
    ]);

    $arrival2 = VesselArrival::factory()->create([
        'location_id' => $repairVessel->id,
        'sign_in_date' => '2026-03-01',
        'sign_out_date' => '2026-03-25',
    ]);

    $this->artisan('vms:consolidate-staying-vessels')
        ->assertSuccessful();

    // Repair vessel arrivals must remain separate and intact!
    expect(VesselArrival::where('location_id', $repairVessel->id)->count())->toBe(2);
    $arrival1->refresh();
    $arrival2->refresh();
    expect($arrival1->sign_out_date)->not->toBeNull();
    expect($arrival2->sign_out_date)->not->toBeNull();
});
