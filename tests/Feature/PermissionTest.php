<?php

use App\Models\Location;
use App\Models\Staff;
use App\Models\User;
use App\Models\VesselArrival;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;

it('forbids a security-role user from creating staff (403 JSON)', function () {
    actingAsSecurity();

    $response = $this->postJson('/api/staffs', [
        'staff_id' => 'STF-9001',
        'name' => 'New Staff',
        'department' => 'IT',
    ]);

    $response->assertStatus(403)->assertJsonStructure(['message']);
});

it('forbids a security-role user from deleting a visitor (403 JSON)', function () {
    $visitor = Visitor::factory()->create();
    actingAsSecurity();

    $this->deleteJson("/api/visitors/{$visitor->id}")
        ->assertStatus(403)
        ->assertJsonStructure(['message']);
});

it('forbids a security-role user from blacklisting a visitor (403 JSON)', function () {
    $visitor = Visitor::factory()->create();
    actingAsSecurity();

    $this->postJson("/api/visitors/{$visitor->id}/blacklist", ['reason' => 'x'])
        ->assertStatus(403)
        ->assertJsonStructure(['message']);
});

it('forbids a security-role user from listing users (403 JSON)', function () {
    actingAsSecurity();

    $this->getJson('/api/users')
        ->assertStatus(403)
        ->assertJsonStructure(['message']);
});

it('allows a security-role user to list visitors (200)', function () {
    Visitor::factory()->count(2)->create();
    actingAsSecurity();

    $this->getJson('/api/visitors')->assertOk();
});

it('allows a security-role user to create and check out a visit (200/201)', function () {
    $staff = Staff::factory()->create();
    $card = VisitorCard::factory()->create();
    $location = Location::factory()->create();
    actingAsSecurity();

    $store = $this->postJson('/api/visits', [
        'id_type' => 'ktp',
        'id_number' => '3210000000000001',
        'name' => 'Walk In',
        'staff_id' => $staff->id,
        'location_id' => $location->id,
        'card_ids' => [$card->id],
        'purpose' => 'Meeting',
    ]);
    $store->assertStatus(201);

    $visitId = $store->json('visit.id');
    $this->postJson("/api/visits/{$visitId}/checkout")->assertOk();
});

it('lets an admin pass every permission-gated endpoint the security user is denied', function () {
    $visitor = Visitor::factory()->create();
    actingAsAdmin();

    $this->postJson('/api/staffs', [
        'staff_id' => 'STF-ADMIN-1',
        'name' => 'Admin Made',
        'department' => 'Ops',
    ])->assertStatus(201);

    $this->getJson('/api/users')->assertOk();

    $this->postJson("/api/visitors/{$visitor->id}/blacklist", ['reason' => 'Test ban'])
        ->assertOk();
});

it('rejects unauthenticated requests to protected endpoints with 401', function () {
    $this->getJson('/api/visitors')->assertStatus(401);
    $this->postJson('/api/staffs', [])->assertStatus(401);
    $this->putJson('/api/visits/01a00000-0000-7000-8000-000000000001', [])->assertStatus(401);
});

it('allows security, supervisor, and admin to update an open visit with PUT /visits/{id}', function () {
    $visit = Visit::factory()->create();

    actingAsSecurity();
    $this->putJson("/api/visits/{$visit->id}", ['purpose' => 'Security Edit'])->assertOk();

    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');
    actingAsUser($supervisor);
    $this->putJson("/api/visits/{$visit->id}", ['purpose' => 'Supervisor Edit'])->assertOk();

    actingAsAdmin();
    $this->putJson("/api/visits/{$visit->id}", ['purpose' => 'Admin Edit'])->assertOk();
});

it('forbids a user without update_visits permission from updating a visit (403)', function () {
    $visit = Visit::factory()->create();

    $viewerOnly = User::factory()->create();
    $viewerOnly->givePermissionTo('view_visits');
    actingAsUser($viewerOnly);

    $this->putJson("/api/visits/{$visit->id}", ['purpose' => 'Viewer Edit'])->assertStatus(403);
});

it('forbids a security-role user from accessing vessel arrivals and reports endpoints (403 JSON)', function () {
    actingAsSecurity();

    $this->getJson('/api/vessel-arrivals')->assertStatus(403)->assertJsonStructure(['message']);
    $this->getJson('/api/reports/visits')->assertStatus(403)->assertJsonStructure(['message']);
    $this->getJson('/api/reports/vessels')->assertStatus(403)->assertJsonStructure(['message']);
});

it('allows operator_pos role to check out visits and check in/out crew but forbids managing vessel arrivals', function () {
    $user = User::factory()->create();
    $user->assignRole('operator_pos');
    actingAsUser($user);

    // Can view visits and vessel arrivals
    $this->getJson('/api/visits')->assertOk();
    $this->getJson('/api/vessel-arrivals')->assertOk();

    // Forbids creating a visit (no create_visits)
    $this->postJson('/api/visits', [])->assertStatus(403);

    // Forbids creating a vessel arrival (no manage_vessel_arrivals)
    $this->postJson('/api/vessel-arrivals', [])->assertStatus(403);

    // Can check in and check out crew via checkout_visits permission
    $arrival = VesselArrival::factory()->create();
    $crew = Visitor::factory()->crew()->create();
    $arrival->crew()->attach($crew->id, ['status' => 'expected']);

    $this->postJson("/api/vessel-arrivals/{$arrival->id}/crew/check-in", [
        'crew_ids' => [$crew->id],
    ])->assertOk();

    $this->postJson("/api/vessel-arrivals/{$arrival->id}/crew/check-out", [
        'crew_ids' => [$crew->id],
    ])->assertOk();
});
