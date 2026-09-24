<?php

use App\Models\Location;
use App\Models\User;

it('creates a vessel with its IMO number as the code', function () {
    actingAsAdmin();

    $this->postJson('/api/locations', [
        'type' => 'repair_vessel',
        'code' => 'IMO9000001',
        'name' => 'MT. SAMPLE TANKER',
        'flag' => 'PANAMA',
    ])->assertCreated()
        ->assertJsonPath('code', 'IMO9000001')
        ->assertJsonPath('flag', 'PANAMA')
        ->assertJsonPath('is_active', true);
});

it('requires a code for a vessel', function () {
    actingAsAdmin();

    $this->postJson('/api/locations', ['type' => 'tug_boat', 'name' => 'TB. SAMPLE 1'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
});

it('refuses a blank name', function () {
    actingAsAdmin();

    $this->postJson('/api/locations', ['type' => 'barge', 'code' => 'IMO9000002', 'name' => '   '])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('derives a place code from its name and refuses a code typed for a place', function () {
    actingAsAdmin();

    $this->postJson('/api/locations', ['type' => 'location', 'name' => 'Production Office'])
        ->assertCreated()
        ->assertJsonPath('code', 'PRODUCTION_OFFICE');

    $this->postJson('/api/locations', ['type' => 'location', 'name' => 'Main Gate', 'code' => 'GATE'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
});

it('refuses a second place with the same name, even a deleted one', function () {
    actingAsAdmin();
    Location::factory()->place('WORKSHOP A')->create()->delete();

    $this->postJson('/api/locations', ['type' => 'location', 'name' => 'Workshop A'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('refuses a vessel code that another location already uses', function () {
    actingAsAdmin();
    Location::factory()->create(['code' => 'IMO9000003']);

    $this->postJson('/api/locations', ['type' => 'barge', 'code' => 'IMO9000003', 'name' => 'BG. SAMPLE'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
});

it('keeps a place code when the place is renamed', function () {
    actingAsAdmin();
    $place = Location::factory()->place('WAREHOUSE 2')->create();

    $this->putJson("/api/locations/{$place->id}", [
        'type' => 'location',
        'name' => 'Warehouse Two',
        'code' => 'WAREHOUSE_2',
    ])->assertOk()
        ->assertJsonPath('name', 'Warehouse Two')
        ->assertJsonPath('code', 'WAREHOUSE_2');
});

it('lists locations searched by name or code and filtered by type, ignoring case', function () {
    actingAsSecurity();
    Location::factory()->create(['code' => 'IMO9100001', 'name' => 'MT. ALPHA SAMPLE']);
    Location::factory()->create(['code' => 'IMO9100002', 'name' => 'TB. BETA SAMPLE', 'type' => 'tug_boat']);
    Location::factory()->place('ALPHA YARD')->create();

    $this->getJson('/api/locations?search=alpha')
        ->assertOk()
        ->assertJsonPath('total', 2);

    $this->getJson('/api/locations?type=tug_boat')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.code', 'IMO9100002');
});

it('soft-deletes a location', function () {
    actingAsAdmin();
    $vessel = Location::factory()->create();

    $this->deleteJson("/api/locations/{$vessel->id}")->assertOk();

    expect(Location::find($vessel->id))->toBeNull()
        ->and(Location::withTrashed()->find($vessel->id))->not->toBeNull();
});

it('lets security read locations but not change them', function () {
    actingAsSecurity();
    $vessel = Location::factory()->create();

    $this->getJson("/api/locations/{$vessel->id}")->assertOk();
    $this->postJson('/api/locations', ['type' => 'barge', 'code' => 'IMO9000009', 'name' => 'BG. X'])->assertStatus(403);
    $this->deleteJson("/api/locations/{$vessel->id}")->assertStatus(403);
});

it('lets a supervisor add a location', function () {
    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');
    actingAsUser($supervisor);

    $this->postJson('/api/locations', ['type' => 'tug_boat', 'code' => 'IMO9000010', 'name' => 'TB. SAMPLE SUP'])
        ->assertCreated();
});
