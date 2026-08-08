<?php

use App\Models\Staff;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;

beforeEach(function () {
    actingAsAdmin();
});

it('lists active visitors in paginator shape and excludes blacklisted ones', function () {
    Visitor::factory()->count(3)->create();
    $blacklisted = Visitor::factory()->blacklisted()->create(['name' => 'Banned Person']);

    $response = $this->getJson('/api/visitors');

    $response->assertOk()->assertJsonStructure([
        'data', 'current_page', 'total', 'per_page',
    ]);

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->not->toContain($blacklisted->id);
    expect($response->json('total'))->toBe(3);
});

it('filters the visitor list by a case-insensitive search term', function () {
    Visitor::factory()->create(['name' => 'Alpha Bravo']);
    Visitor::factory()->create(['name' => 'Charlie Delta']);

    $response = $this->getJson('/api/visitors?search=alpha');

    $response->assertOk();
    expect($response->json('total'))->toBe(1);
    expect($response->json('data.0.name'))->toBe('Alpha Bravo');
});

it('caps per_page at 100 (B11)', function () {
    Visitor::factory()->count(2)->create();

    $response = $this->getJson('/api/visitors?per_page=500');

    $response->assertOk();
    expect($response->json('per_page'))->toBe(100);
});

it('lists only blacklisted visitors on the blacklisted endpoint', function () {
    Visitor::factory()->count(2)->create();
    $banned = Visitor::factory()->blacklisted()->create();

    $response = $this->getJson('/api/visitors/blacklisted');

    $response->assertOk();
    expect($response->json('total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($banned->id);
});

it('updates a visitor successfully', function () {
    $visitor = Visitor::factory()->create();

    $response = $this->putJson("/api/visitors/{$visitor->id}", [
        'id_type' => 'ktp',
        'id_number' => $visitor->id_number,
        'name' => 'Updated Name',
        'company' => 'New Co',
    ]);

    $response->assertOk();
    expect($visitor->fresh()->name)->toBe('Updated Name');
});

it('rejects a PUT that duplicates an existing (id_type, id_number) with 422 (M3)', function () {
    $a = Visitor::factory()->create(['id_type' => 'ktp', 'id_number' => '1111111111111111']);
    $b = Visitor::factory()->create(['id_type' => 'ktp', 'id_number' => '2222222222222222']);

    $this->putJson("/api/visitors/{$b->id}", [
        'id_type' => 'ktp',
        'id_number' => '1111111111111111',
        'name' => $b->name,
    ])->assertStatus(422);
});

it('rejects changing a blacklisted visitor identity with 422 (M4)', function () {
    $visitor = Visitor::factory()->blacklisted()->create([
        'id_type' => 'ktp',
        'id_number' => '3333333333333333',
    ]);

    $this->putJson("/api/visitors/{$visitor->id}", [
        'id_type' => 'ktp',
        'id_number' => '4444444444444444',
        'name' => $visitor->name,
    ])->assertStatus(422);
});

it('blacklists a visitor, setting the flag and reason via forceFill (B6)', function () {
    $visitor = Visitor::factory()->create();

    $this->postJson("/api/visitors/{$visitor->id}/blacklist", ['reason' => 'Caught stealing'])
        ->assertOk();

    $fresh = $visitor->fresh();
    expect($fresh->is_blacklisted)->toBeTrue();
    expect($fresh->blacklist_reason)->toBe('Caught stealing');
});

it('removes a visitor from the blacklist and clears the reason', function () {
    $visitor = Visitor::factory()->blacklisted('Some reason')->create();

    $this->postJson("/api/visitors/{$visitor->id}/unblacklist")->assertOk();

    $fresh = $visitor->fresh();
    expect($fresh->is_blacklisted)->toBeFalse();
    expect($fresh->blacklist_reason)->toBeNull();
});

it('refuses to delete a visitor with visit history and leaves data intact (C1)', function () {
    $staff = Staff::factory()->create();
    $card = VisitorCard::factory()->create();

    $store = $this->postJson('/api/visits', [
        'id_type' => 'ktp',
        'id_number' => '5555555555555555',
        'name' => 'Has History',
        'staff_id' => $staff->id,
        'card_ids' => [$card->id],
        'purpose' => 'Meeting',
    ]);
    $store->assertStatus(201);
    $visitId = $store->json('visit.id');
    $visitor = Visitor::where('id_number', '5555555555555555')->firstOrFail();

    $this->deleteJson("/api/visitors/{$visitor->id}")->assertStatus(422);

    expect(Visitor::find($visitor->id))->not->toBeNull();
    expect(Visit::find($visitId))->not->toBeNull();
    expect($card->fresh()->status)->toBe('in_use');
});

it('deletes a visitor with no visit history', function () {
    $visitor = Visitor::factory()->create();

    $this->deleteJson("/api/visitors/{$visitor->id}")->assertOk();

    expect(Visitor::find($visitor->id))->toBeNull();
});
