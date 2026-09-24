<?php

use App\Models\Location;
use App\Models\VesselArrival;
use App\Models\Visitor;

it('creates a vessel arrival and snapshots vessel_name', function () {
    actingAsSupervisor();
    $vessel = Location::factory()->create(['name' => 'MV. PACIFIC GLORY']);

    $response = $this->postJson('/api/vessel-arrivals', [
        'location_id' => $vessel->id,
        'sign_in_date' => '2026-09-20',
        'sign_in_port' => 'BATAM',
        'captain' => 'Capt. Robert',
    ])->assertCreated();

    $response->assertJsonPath('location_id', $vessel->id)
        ->assertJsonPath('vessel_name', 'MV. PACIFIC GLORY')
        ->assertJsonPath('sign_in_date', '2026-09-20')
        ->assertJsonPath('sign_in_port', 'BATAM')
        ->assertJsonPath('captain', 'Capt. Robert');

    expect(VesselArrival::where('location_id', $vessel->id)->count())->toBe(1);
});

it('creates a vessel arrival with linked crew in one transaction', function () {
    actingAsSupervisor();
    $vessel = Location::factory()->create();
    $crew1 = Visitor::factory()->crew()->create();
    $crew2 = Visitor::factory()->crew()->create();

    $response = $this->postJson('/api/vessel-arrivals', [
        'location_id' => $vessel->id,
        'sign_in_date' => '2026-09-21',
        'crew_ids' => [$crew1->id, $crew2->id],
    ])->assertCreated();

    $arrivalId = $response->json('id');
    $arrival = VesselArrival::with('crew')->find($arrivalId);

    expect($arrival->crew)->toHaveCount(2);
    expect($arrival->crew->pluck('id'))->toContain($crew1->id, $crew2->id);
});

it('requires location_id and sign_in_date on creation', function () {
    actingAsSupervisor();

    $this->postJson('/api/vessel-arrivals', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['location_id', 'sign_in_date']);
});

it('rejects sign_out_date earlier than sign_in_date', function () {
    actingAsSupervisor();
    $vessel = Location::factory()->create();

    $this->postJson('/api/vessel-arrivals', [
        'location_id' => $vessel->id,
        'sign_in_date' => '2026-09-20',
        'sign_out_date' => '2026-09-18',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['sign_out_date']);
});

it('shows a vessel arrival with vessel and crew', function () {
    actingAsSupervisor();
    $arrival = VesselArrival::factory()->create();
    $crew = Visitor::factory()->crew()->create(['name' => 'Sailor Jim']);
    $arrival->crew()->attach($crew->id);

    $this->getJson("/api/vessel-arrivals/{$arrival->id}")
        ->assertOk()
        ->assertJsonPath('id', $arrival->id)
        ->assertJsonPath('vessel.id', $arrival->location_id)
        ->assertJsonPath('crew.0.name', 'Sailor Jim');
});

it('updates a vessel arrival partially without clobbering other fields', function () {
    actingAsSupervisor();
    $arrival = VesselArrival::factory()->create([
        'captain' => 'Old Captain',
        'sign_in_port' => 'BATAM',
        'sign_out_port' => 'SINGAPORE',
    ]);

    $this->putJson("/api/vessel-arrivals/{$arrival->id}", [
        'captain' => 'New Captain',
    ])->assertOk()
        ->assertJsonPath('captain', 'New Captain')
        ->assertJsonPath('sign_in_port', 'BATAM')
        ->assertJsonPath('sign_out_port', 'SINGAPORE');

    $arrival->refresh();
    expect($arrival->captain)->toBe('New Captain');
    expect($arrival->sign_in_port)->toBe('BATAM');
});

it('updates vessel_name snapshot when location_id is changed', function () {
    actingAsSupervisor();
    $vessel1 = Location::factory()->create(['name' => 'MV. OLD VESSEL']);
    $vessel2 = Location::factory()->create(['name' => 'MV. NEW VESSEL']);

    $arrival = VesselArrival::factory()->create([
        'location_id' => $vessel1->id,
        'vessel_name' => 'MV. OLD VESSEL',
    ]);

    $this->putJson("/api/vessel-arrivals/{$arrival->id}", [
        'location_id' => $vessel2->id,
    ])->assertOk()
        ->assertJsonPath('location_id', $vessel2->id)
        ->assertJsonPath('vessel_name', 'MV. NEW VESSEL');
});

it('syncs crew list on update', function () {
    actingAsSupervisor();
    $arrival = VesselArrival::factory()->create();
    $crew1 = Visitor::factory()->crew()->create();
    $crew2 = Visitor::factory()->crew()->create();
    $crew3 = Visitor::factory()->crew()->create();

    $arrival->crew()->attach([$crew1->id, $crew2->id]);

    $this->putJson("/api/vessel-arrivals/{$arrival->id}", [
        'crew_ids' => [$crew2->id, $crew3->id],
    ])->assertOk();

    $arrival->refresh();
    expect($arrival->crew)->toHaveCount(2);
    expect($arrival->crew->pluck('id'))->toContain($crew2->id, $crew3->id);
    expect($arrival->crew->pluck('id'))->not->toContain($crew1->id);
});

it('filters vessel arrivals by date range, vessel_name, and stay_status', function () {
    actingAsSupervisor();
    $vesselA = Location::factory()->create(['name' => 'MV. ARCTIC VOYAGER']);
    $vesselB = Location::factory()->create(['name' => 'MV. BALTIC TRADER']);

    VesselArrival::factory()->create([
        'location_id' => $vesselA->id,
        'vessel_name' => 'MV. ARCTIC VOYAGER',
        'sign_in_date' => '2026-09-01',
        'sign_out_date' => '2026-09-10',
    ]);

    VesselArrival::factory()->create([
        'location_id' => $vesselB->id,
        'vessel_name' => 'MV. BALTIC TRADER',
        'sign_in_date' => '2026-09-15',
        'sign_out_date' => null,
    ]);

    // Filter by vessel_name
    $this->getJson('/api/vessel-arrivals?vessel_name=arctic')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.vessel_name', 'MV. ARCTIC VOYAGER');

    // Filter by date range
    $this->getJson('/api/vessel-arrivals?date_from=2026-09-12&date_to=2026-09-20')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.vessel_name', 'MV. BALTIC TRADER');

    // Filter by stay_status = not_out (still in port)
    $this->getJson('/api/vessel-arrivals?stay_status=not_out')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.vessel_name', 'MV. BALTIC TRADER');

    // Filter by stay_status = out
    $this->getJson('/api/vessel-arrivals?stay_status=out')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.vessel_name', 'MV. ARCTIC VOYAGER');
});

it('deletes a vessel arrival successfully', function () {
    actingAsSupervisor();
    $arrival = VesselArrival::factory()->create();

    $this->deleteJson("/api/vessel-arrivals/{$arrival->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Vessel arrival deleted successfully');

    expect(VesselArrival::find($arrival->id))->toBeNull();
});
