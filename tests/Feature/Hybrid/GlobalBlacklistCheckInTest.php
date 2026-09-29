<?php

use App\Enums\BlacklistReasonCategory;
use App\Models\ActivityLog;
use App\Models\GlobalBlacklist;
use App\Models\Location;
use App\Models\Staff;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use Illuminate\Support\Str;

/*
 * Hybrid spec §6.2 (decisions BE-6, SEC-4, SEC-7): the global blacklist mirror is checked before any
 * visitor is created, for new visitors too. Same number and document type = refused with the category
 * and the registering yard; same number under another document type = a yellow warning for the officer
 * to verify, never an automatic refusal. A supervisor may let one visit through with a written reason.
 */

function globalEntry(array $overrides = []): GlobalBlacklist
{
    return GlobalBlacklist::create(array_merge([
        'hub_id' => (string) Str::uuid7(),
        'id_type' => 'ktp',
        'id_number' => '3210000000000001',
        'name' => 'Budi Santoso',
        'reason_category' => BlacklistReasonCategory::AssetTheftDamage,
        'registered_by_yard' => 'ASL',
        'is_active' => true,
        'sync_seq' => random_int(1, 1_000_000),
    ], $overrides));
}

/** @param array<string, mixed> $overrides */
function checkInPayload(array $overrides = []): array
{
    return array_merge([
        'id_type' => 'ktp',
        'id_number' => '3210-0000-0000-0001',
        'name' => 'Budi Santoso',
        'staff_id' => Staff::factory()->create()->id,
        'location_id' => Location::factory()->create()->id,
        'card_ids' => [VisitorCard::factory()->create()->id],
        'purpose' => 'Delivery',
    ], $overrides);
}

it('refuses a new visitor whose number and document type are on the global blacklist', function () {
    actingAsSecurity();
    globalEntry();

    $this->postJson('/api/visits', checkInPayload())
        ->assertStatus(422)
        ->assertJsonPath('message', 'This visitor is on the global blacklist.')
        ->assertJsonPath('global_blacklist.reason_category', 'asset_theft_damage')
        ->assertJsonPath('global_blacklist.reason_label', 'Pencurian/kerusakan aset')
        ->assertJsonPath('global_blacklist.registered_by_yard', 'ASL')
        ->assertJsonPath('can_override', false);

    expect(Visitor::count())->toBe(0)->and(Visit::count())->toBe(0);
});

it('refuses a known visitor too', function () {
    actingAsSecurity();
    Visitor::factory()->create(['id_type' => 'ktp', 'id_number' => '3210000000000001']);
    globalEntry();

    $this->postJson('/api/visits', checkInPayload())->assertStatus(422);

    expect(Visit::count())->toBe(0);
});

it('lets the same number under another document type through with a warning', function () {
    actingAsSecurity();
    globalEntry(['id_type' => 'passport']);

    $this->postJson('/api/visits', checkInPayload())
        ->assertCreated()
        ->assertJsonPath('warnings.0.code', 'global_blacklist_other_document')
        ->assertJsonPath('warnings.0.id_type', 'passport')
        ->assertJsonPath('warnings.0.registered_by_yard', 'ASL');
});

it('ignores revoked global entries', function () {
    actingAsSecurity();
    globalEntry(['is_active' => false]);

    $this->postJson('/api/visits', checkInPayload())->assertCreated()->assertJsonMissingPath('warnings.0');
});

it('lets a supervisor override one visit with a written reason, and logs it', function () {
    $supervisor = actingAsSupervisor();
    $entry = globalEntry();

    $this->postJson('/api/visits', checkInPayload(['id_type' => 'ktp']))
        ->assertStatus(422)
        ->assertJsonPath('can_override', true);

    $response = $this->postJson('/api/visits', checkInPayload([
        'override_global_blacklist' => true,
        'override_reason' => 'Dikonfirmasi ASL lewat telepon: salah orang',
    ]))->assertCreated();

    $log = ActivityLog::where('action', 'blacklist_override')->sole();
    expect($log->causer_id)->toBe($supervisor->id)
        ->and($log->new_values)->toMatchArray([
            'hub_id' => $entry->hub_id,
            'id_number' => '3210000000000001',
            'reason' => 'Dikonfirmasi ASL lewat telepon: salah orang',
            'visit_id' => $response->json('visit.id'),
        ])
        ->and(GlobalBlacklist::sole()->is_active)->toBeTrue();
});

it('refuses an override without a real reason', function () {
    actingAsSupervisor();
    globalEntry();

    $this->postJson('/api/visits', checkInPayload(['override_global_blacklist' => true, 'override_reason' => 'ok']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('override_reason');
});

it('refuses an override from someone who cannot manage the blacklist', function () {
    actingAsSecurity();
    globalEntry();

    $this->postJson('/api/visits', checkInPayload([
        'override_global_blacklist' => true,
        'override_reason' => 'Petugas yakin ini orang lain',
    ]))->assertForbidden();

    expect(Visit::count())->toBe(0)
        ->and(ActivityLog::where('action', 'blacklist_override')->count())->toBe(0);
});

it('never lets an override pass the local blacklist', function () {
    actingAsSupervisor();
    Visitor::factory()->create(['id_type' => 'ktp', 'id_number' => '3210000000000001', 'is_blacklisted' => true]);

    $this->postJson('/api/visits', checkInPayload([
        'override_global_blacklist' => true,
        'override_reason' => 'Mencoba melewati blacklist lokal',
    ]))->assertStatus(422)->assertJsonPath('message', 'This visitor is blacklisted and cannot be registered for a new visit.');
});

it('reports global entries for a number when the officer types it', function () {
    actingAsSecurity();
    globalEntry();
    globalEntry(['id_type' => 'passport', 'reason_category' => BlacklistReasonCategory::Misconduct, 'registered_by_yard' => 'CIS']);
    globalEntry(['id_type' => 'sim', 'is_active' => false]);

    $response = $this->getJson('/api/visitors/check-ktp/3210-0000 0000 0001')->assertOk();

    expect($response->json('data'))->toBeNull()
        ->and(collect($response->json('global_blacklist'))->map(fn (array $e) => [$e['id_type'], $e['reason_label'], $e['registered_by_yard']])->all())
        ->toBe([['ktp', 'Pencurian/kerusakan aset', 'ASL'], ['passport', 'Perilaku tidak pantas', 'CIS']]);
});
