<?php

use App\Enums\BlacklistReasonCategory;
use App\Enums\PreRegistrationStatus;
use App\Enums\SyncKey;
use App\Models\GlobalBlacklist;
use App\Models\GlobalBlacklistOutbox;
use App\Models\PreRegistration;
use App\Models\SyncStateLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Hybrid roadmap step 5: the gate holds mirrors only (decision PE-2). The hub owns the
 * yard registry, the HMAC secrets and the global order (sync_seq, PE-3).
 */

function mirrorEntry(array $overrides = []): GlobalBlacklist
{
    return GlobalBlacklist::create(array_merge([
        'hub_id' => (string) Str::uuid7(),
        'id_type' => 'ktp',
        'id_number' => '3210-0000 0000 0001',
        'name' => 'Budi',
        'reason_category' => BlacklistReasonCategory::K3Violation,
        'registered_by_yard' => 'ASL',
        'is_active' => true,
        'sync_seq' => random_int(1, 1_000_000_000),
    ], $overrides));
}

it('keeps secrets, versions and the yard registry out of the gate database', function () {
    expect(Schema::hasTable('yard_companies'))->toBeFalse()
        ->and(Schema::hasColumns('global_blacklists', ['hub_id', 'reason_category', 'sync_seq']))->toBeTrue()
        ->and(Schema::hasColumn('global_blacklists', 'version'))->toBeFalse()
        ->and(Schema::hasColumn('global_blacklists', 'reason'))->toBeFalse()
        ->and(Schema::hasColumn('pre_registrations', 'yard_code'))->toBeFalse();
});

it('stores a pulled pre-registration with a normalized identity and token and no gender', function () {
    $pre = PreRegistration::create([
        'token' => ' pr-ab12-cd34-ef56 ',
        'id_type' => 'ktp',
        'id_number' => '3210-0000 0000 0001',
        'name' => 'Budi',
        'scheduled_date' => '2026-10-01',
        'pulled_at' => now(),
    ])->fresh();

    expect($pre->token)->toBe('PR-AB12-CD34-EF56')
        ->and($pre->id_number)->toBe('3210000000000001')
        ->and($pre->gender)->toBeNull()
        ->and($pre->status)->toBe(PreRegistrationStatus::Pending)
        ->and(PreRegistration::normalizeToken(' pr-ab12-cd34-ef56 '))->toBe('PR-AB12-CD34-EF56');
});

it('finds an active global entry by number whatever the document type', function () {
    $ktp = mirrorEntry();
    mirrorEntry(['id_type' => 'passport', 'id_number' => '3210000000000001', 'is_active' => false]);
    mirrorEntry(['id_number' => '9999']);

    $matches = GlobalBlacklist::activeForNumber('3210 0000-0000 0001')->get();

    expect($matches)->toHaveCount(1)
        ->and($matches->first()->is($ktp))->toBeTrue()
        ->and($matches->first()->reason_category)->toBe(BlacklistReasonCategory::K3Violation);
});

it('holds one mirror row per hub entry', function () {
    $hubId = (string) Str::uuid7();
    mirrorEntry(['hub_id' => $hubId]);

    expect(fn () => mirrorEntry(['hub_id' => $hubId]))->toThrow(QueryException::class);
});

it('queues promote and revoke requests until they reach the hub', function () {
    $queued = GlobalBlacklistOutbox::create([
        'action' => 'add',
        'id_type' => 'ktp',
        'id_number' => '3210-0000 0000 0001',
        'name' => 'Budi',
        'reason_category' => BlacklistReasonCategory::AssetTheftDamage,
        'local_note' => 'Stays on this server only',
    ]);
    GlobalBlacklistOutbox::create([
        'action' => 'revoke', 'id_type' => 'ktp', 'id_number' => '1', 'name' => 'X', 'sent_at' => now(),
    ]);

    expect(GlobalBlacklistOutbox::pending()->pluck('id')->all())->toBe([$queued->id])
        ->and($queued->fresh()->id_number)->toBe('3210000000000001')
        ->and($queued->fresh()->attempts)->toBe(0);
});

it('keeps one cursor row per sync channel', function () {
    SyncStateLog::create(['sync_key' => SyncKey::BlacklistPull, 'last_seq' => 42]);

    $log = SyncStateLog::find(SyncKey::BlacklistPull->value);

    expect($log->last_seq)->toBe(42)
        ->and($log->sync_key)->toBe(SyncKey::BlacklistPull)
        ->and(SyncKey::cases())->toHaveCount(3);
});

it('offers exactly the four agreed global reason categories', function () {
    expect(array_map(fn ($c) => $c->value, BlacklistReasonCategory::cases()))
        ->toBe(['k3_violation', 'asset_theft_damage', 'misconduct', 'other'])
        ->and(BlacklistReasonCategory::K3Violation->label())->toBe('Pelanggaran K3');
});
