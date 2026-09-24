<?php

use App\Enums\VisitorType;
use App\Models\Location;
use App\Models\VesselArrival;
use App\Models\Visitor;

it('forbids unauthenticated user from fetching crew candidates', function () {
    $this->getJson('/api/vessel-arrivals/crew-candidates')
        ->assertUnauthorized();
});

it('forbids security role from fetching crew candidates', function () {
    actingAsSecurity();
    $vessel = Location::factory()->create();

    $this->getJson("/api/vessel-arrivals/crew-candidates?location_id={$vessel->id}")
        ->assertForbidden();
});

it('validates location_id is required, uuid, and exists', function () {
    actingAsSupervisor();

    $this->getJson('/api/vessel-arrivals/crew-candidates')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['location_id']);

    $this->getJson('/api/vessel-arrivals/crew-candidates?location_id=invalid-uuid')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['location_id']);

    $nonExistentUuid = '01921312-3456-789a-bcde-f0123456789a';
    $this->getJson("/api/vessel-arrivals/crew-candidates?location_id={$nonExistentUuid}")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['location_id']);
});

it('validates vessel_arrival_id must be uuid and exist if provided', function () {
    actingAsSupervisor();
    $vessel = Location::factory()->create();

    $this->getJson("/api/vessel-arrivals/crew-candidates?location_id={$vessel->id}&vessel_arrival_id=not-a-uuid")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['vessel_arrival_id']);

    $nonExistentUuid = '01921312-3456-789a-bcde-f0123456789a';
    $this->getJson("/api/vessel-arrivals/crew-candidates?location_id={$vessel->id}&vessel_arrival_id={$nonExistentUuid}")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['vessel_arrival_id']);
});

it('returns crew candidates matching the vessel location_id ordered by name', function () {
    actingAsSupervisor();
    $vessel = Location::factory()->create();
    $otherVessel = Location::factory()->create();

    $crewCharlie = Visitor::factory()->crew($vessel)->create([
        'name' => 'Charlie Seaman',
        'occupation' => 'Chief Engineer',
        'nationality' => 'INDONESIA',
    ]);
    $crewAlice = Visitor::factory()->crew($vessel)->create([
        'name' => 'Alice Deckhand',
        'occupation' => 'Bosun',
        'nationality' => 'INDONESIA',
    ]);

    // Regular visitor belonging to vessel (should be excluded)
    Visitor::factory()->create([
        'name' => 'Bob Guest',
        'visitor_type' => VisitorType::Visitor->value,
        'vessel_id' => $vessel->id,
    ]);

    // Crew on another vessel (should be excluded)
    Visitor::factory()->crew($otherVessel)->create([
        'name' => 'David Sailor',
    ]);

    $response = $this->getJson("/api/vessel-arrivals/crew-candidates?location_id={$vessel->id}")
        ->assertOk();

    $data = $response->json();
    expect($data)->toHaveCount(2);
    expect($data[0]['name'])->toBe('Alice Deckhand');
    expect($data[1]['name'])->toBe('Charlie Seaman');

    $first = $data[0];
    expect($first)->toHaveKeys([
        'id', 'visitor_no', 'name', 'id_type', 'id_number',
        'gender', 'occupation', 'nationality', 'certificate',
        'certificate_no', 'seaman_book_no', 'is_blacklisted', 'blacklist_reason',
    ]);
});

it('includes crew already attached to vessel_arrival even if assigned vessel_id differs', function () {
    actingAsSupervisor();
    $vesselA = Location::factory()->create();
    $vesselB = Location::factory()->create();

    $arrival = VesselArrival::factory()->create(['location_id' => $vesselA->id]);

    $crewHome = Visitor::factory()->crew($vesselA)->create(['name' => 'Home Crew']);
    $crewForeign = Visitor::factory()->crew($vesselB)->create(['name' => 'Foreign Crew Attached']);
    $crewOther = Visitor::factory()->crew($vesselB)->create(['name' => 'Other Crew Unattached']);

    // Attach both home crew and foreign crew to arrival
    $arrival->crew()->attach([$crewHome->id, $crewForeign->id]);

    $response = $this->getJson("/api/vessel-arrivals/crew-candidates?location_id={$vesselA->id}&vessel_arrival_id={$arrival->id}")
        ->assertOk();

    $data = $response->json();
    $ids = collect($data)->pluck('id')->all();

    expect($ids)->toContain($crewHome->id);
    expect($ids)->toContain($crewForeign->id);
    expect($ids)->not->toContain($crewOther->id);

    // Verify distinct - no duplicates
    expect(count($ids))->toBe(count(array_unique($ids)));
    expect($data)->toHaveCount(2);
});

it('returns blacklisted status and reason for crew candidates', function () {
    actingAsSupervisor();
    $vessel = Location::factory()->create();

    $blacklistedCrew = Visitor::factory()->crew($vessel)->blacklisted('Port infraction')->create([
        'name' => 'Blacklisted Sailor',
    ]);

    $response = $this->getJson("/api/vessel-arrivals/crew-candidates?location_id={$vessel->id}")
        ->assertOk();

    $data = $response->json();
    expect($data)->toHaveCount(1);
    expect($data[0]['id'])->toBe($blacklistedCrew->id);
    expect($data[0]['is_blacklisted'])->toBeTrue();
    expect($data[0]['blacklist_reason'])->toBe('Port infraction');
});
