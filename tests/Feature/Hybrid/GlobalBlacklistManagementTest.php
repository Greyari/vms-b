<?php

use App\Enums\BlacklistReasonCategory;
use App\Models\ActivityLog;
use App\Models\GlobalBlacklist;
use App\Models\GlobalBlacklistOutbox;
use App\Models\Visitor;
use Illuminate\Support\Str;

/*
 * Hybrid spec §6.3 (decisions PE-5, FE-4b, SEC-3, SEC-6d, BE-4): a supervisor deliberately promotes a
 * locally blacklisted visitor to the global list with a fixed category and a local note, and revokes
 * only what this yard registered. Both are queued locally and logged, then sent by the sync command;
 * the local note never leaves the yard.
 */

beforeEach(function () {
    config(['vms.sync.yard_code' => 'CIS']);
});

function mirrored(array $overrides = []): GlobalBlacklist
{
    return GlobalBlacklist::create(array_merge([
        'hub_id' => (string) Str::uuid7(),
        'id_type' => 'ktp',
        'id_number' => '3210000000000001',
        'name' => 'Budi Santoso',
        'reason_category' => BlacklistReasonCategory::K3Violation,
        'registered_by_yard' => 'CIS',
        'is_active' => true,
        'sync_seq' => random_int(1, 1_000_000),
    ], $overrides));
}

function blacklistedVisitor(array $overrides = []): Visitor
{
    return Visitor::factory()->create(array_merge([
        'id_type' => 'ktp', 'id_number' => '3210000000000005', 'name' => 'Joko', 'is_blacklisted' => true, 'blacklist_reason' => 'Mencuri kabel',
    ], $overrides));
}

it('lists the mirror with who may revoke what', function () {
    actingAsSecurity();
    mirrored(['name' => 'Ours']);
    mirrored(['id_number' => '2222', 'name' => 'Theirs', 'registered_by_yard' => 'ASL']);
    mirrored(['id_number' => '3333', 'name' => 'Revoked', 'is_active' => false]);

    $all = $this->getJson('/api/global-blacklists?status=all')->assertOk();
    expect(collect($all->json('data'))->pluck('name')->sort()->values()->all())->toBe(['Ours', 'Revoked', 'Theirs']);

    $active = collect($this->getJson('/api/global-blacklists')->json('data'))->keyBy('name');
    expect($active->keys()->sort()->values()->all())->toBe(['Ours', 'Theirs'])
        ->and($active['Ours']['can_revoke'])->toBeTrue()
        ->and($active['Theirs']['can_revoke'])->toBeFalse()
        ->and($active['Ours']['reason_label'])->toBe('Pelanggaran K3');

    expect(collect($this->getJson('/api/global-blacklists?search=3210')->json('data'))->pluck('name')->all())->toBe(['Ours']);
});

it('queues a promotion of a locally blacklisted visitor with a fixed category and a local note', function () {
    $supervisor = actingAsSupervisor();
    $visitor = blacklistedVisitor();

    $this->postJson('/api/global-blacklists', [
        'visitor_id' => $visitor->id,
        'reason_category' => 'asset_theft_damage',
        'local_note' => 'Tertangkap CCTV mengambil kabel di dok 2',
    ])->assertCreated()->assertJsonPath('data.status', 'pending');

    expect(GlobalBlacklistOutbox::sole())
        ->action->toBe('add')
        ->id_number->toBe('3210000000000005')
        ->name->toBe('Joko')
        ->reason_category->toBe(BlacklistReasonCategory::AssetTheftDamage)
        ->local_note->toBe('Tertangkap CCTV mengambil kabel di dok 2')
        ->requested_by->toBe($supervisor->id)
        ->and(ActivityLog::where('subject_type', GlobalBlacklistOutbox::class)->where('action', 'created')->count())->toBe(1);
});

it('promotes only a visitor already on the local blacklist', function () {
    actingAsSupervisor();
    $visitor = blacklistedVisitor(['is_blacklisted' => false]);

    $this->postJson('/api/global-blacklists', ['visitor_id' => $visitor->id, 'reason_category' => 'other', 'local_note' => 'Alasan lokal'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('visitor_id');
});

it('refuses a promotion that is already global or already queued', function () {
    actingAsSupervisor();
    $listed = blacklistedVisitor(['id_number' => '3210000000000001']);
    mirrored(['registered_by_yard' => 'ASL']);
    $queued = blacklistedVisitor(['id_number' => '4444']);
    $payload = fn (Visitor $v) => ['visitor_id' => $v->id, 'reason_category' => 'other', 'local_note' => 'Alasan lokal'];

    $this->postJson('/api/global-blacklists', $payload($listed))->assertUnprocessable()->assertJsonValidationErrors('visitor_id');
    $this->postJson('/api/global-blacklists', $payload($queued))->assertCreated();
    $this->postJson('/api/global-blacklists', $payload($queued))->assertUnprocessable()->assertJsonValidationErrors('visitor_id');
});

it('validates the category and requires the local note', function (array $payload, string $field) {
    actingAsSupervisor();
    $visitor = blacklistedVisitor();

    $this->postJson('/api/global-blacklists', array_merge(['visitor_id' => $visitor->id, 'reason_category' => 'other', 'local_note' => 'Alasan'], $payload))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'unknown category' => [['reason_category' => 'theft'], 'reason_category'],
    'no local note' => [['local_note' => ''], 'local_note'],
]);

it('queues the revocation of an entry this yard registered', function () {
    actingAsSupervisor();
    $entry = mirrored();

    $this->postJson("/api/global-blacklists/{$entry->id}/revoke", ['local_note' => 'Keputusan manajemen: dicabut'])->assertCreated();

    expect(GlobalBlacklistOutbox::sole())->action->toBe('revoke')->id_number->toBe('3210000000000001')->reason_category->toBeNull();
});

it('refuses to revoke another yard entry or a revoked one', function (array $state, int $status) {
    actingAsSupervisor();
    $entry = mirrored($state);

    $this->postJson("/api/global-blacklists/{$entry->id}/revoke", ['local_note' => 'Mencoba mencabut'])->assertStatus($status);

    expect(GlobalBlacklistOutbox::count())->toBe(0);
})->with([
    'registered by ASL' => [['registered_by_yard' => 'ASL'], 403],
    'already revoked' => [['is_active' => false], 422],
]);

it('keeps security out of promotion and revocation', function () {
    actingAsSecurity();
    $visitor = blacklistedVisitor();
    $entry = mirrored();

    $this->postJson('/api/global-blacklists', ['visitor_id' => $visitor->id, 'reason_category' => 'other', 'local_note' => 'x'])->assertForbidden();
    $this->postJson("/api/global-blacklists/{$entry->id}/revoke", ['local_note' => 'x'])->assertForbidden();
    $this->getJson('/api/global-blacklists/outbox')->assertForbidden();
});

it('shows the outbox with the state of each request', function () {
    actingAsSupervisor();
    GlobalBlacklistOutbox::create(['action' => 'add', 'id_type' => 'ktp', 'id_number' => '1', 'name' => 'A', 'reason_category' => BlacklistReasonCategory::Other]);
    GlobalBlacklistOutbox::create(['action' => 'add', 'id_type' => 'ktp', 'id_number' => '2', 'name' => 'B', 'reason_category' => BlacklistReasonCategory::Other, 'sent_at' => now()]);
    GlobalBlacklistOutbox::create(['action' => 'revoke', 'id_type' => 'ktp', 'id_number' => '3', 'name' => 'C', 'sent_at' => now(), 'last_error' => 'Only the yard that registered this entry can revoke it.']);

    $rows = collect($this->getJson('/api/global-blacklists/outbox')->assertOk()->json('data'))->keyBy('name');

    expect($rows['A']['status'])->toBe('pending')
        ->and($rows['B']['status'])->toBe('sent')
        ->and($rows['C']['status'])->toBe('rejected')
        ->and($rows['C']['last_error'])->toContain('Only the yard');
});
