<?php

use App\Models\Staff;
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
    actingAsSecurity();

    $store = $this->postJson('/api/visits', [
        'id_type' => 'ktp',
        'id_number' => '3210000000000001',
        'name' => 'Walk In',
        'staff_id' => $staff->id,
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
});
