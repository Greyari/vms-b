<?php

use App\Enums\BlacklistReasonCategory;
use App\Enums\SyncKey;
use App\Models\GlobalBlacklistOutbox;
use App\Models\SyncStateLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/*
 * Hybrid spec §8.3 (decisions FE-4c, PE-6): the header shows green when both pulls succeeded within five
 * minutes, yellow within thirty, red after that or never, and "off" while sync is switched off.
 */

beforeEach(function () {
    config(['vms.sync' => array_merge(config('vms.sync', []), [
        'enabled' => true, 'yard_code' => 'CIS', 'hub_url' => 'https://hub.test/api/v1', 'key_id' => '2026a', 'secret' => 'test-only-not-a-real-secret-gate',
    ])]);
    $this->travelTo(Carbon::parse('2026-10-01 09:00:00', 'Asia/Jakarta'));
});

function syncedMinutesAgo(?int $blacklist, ?int $preRegistrations): void
{
    foreach ([SyncKey::BlacklistPull->value => $blacklist, SyncKey::PreRegistrations->value => $preRegistrations] as $key => $minutes) {
        SyncStateLog::create([
            'sync_key' => $key,
            'status' => 'ok',
            'last_attempt_at' => now(),
            'last_success_at' => $minutes === null ? null : now()->subMinutes($minutes),
        ]);
    }
}

it('is off while sync is switched off', function () {
    actingAsSecurity();
    config(['vms.sync.enabled' => false]);

    $this->getJson('/api/sync/status')->assertOk()->assertJsonPath('level', 'off')->assertJsonPath('enabled', false);
});

it('turns yellow and red as the oldest pull ages', function (?int $blacklist, ?int $preRegistrations, string $level) {
    actingAsSecurity();
    syncedMinutesAgo($blacklist, $preRegistrations);

    $this->getJson('/api/sync/status')->assertOk()->assertJsonPath('level', $level);
})->with([
    'both fresh' => [2, 3, 'green'],
    'one at ten minutes' => [2, 10, 'yellow'],
    'one at forty minutes' => [40, 3, 'red'],
    'one never' => [2, null, 'red'],
]);

it('treats a hub with the global blacklist switched off as fine', function () {
    actingAsSecurity();
    syncedMinutesAgo(null, 2);
    SyncStateLog::where('sync_key', SyncKey::BlacklistPull->value)->update(['status' => 'disabled']);

    $this->getJson('/api/sync/status')->assertOk()->assertJsonPath('level', 'green');
});

it('reports the minutes since the last sync and the queued requests', function () {
    actingAsSecurity();
    syncedMinutesAgo(4, 7);
    GlobalBlacklistOutbox::create(['action' => 'add', 'id_type' => 'ktp', 'id_number' => '1', 'name' => 'A', 'reason_category' => BlacklistReasonCategory::Other]);

    $this->getJson('/api/sync/status')
        ->assertOk()
        ->assertJsonPath('yard_code', 'CIS')
        ->assertJsonPath('minutes_since_sync', 7)
        ->assertJsonPath('pending_outbox', 1)
        ->assertJsonCount(3, 'channels');
});

it('lets a supervisor run a cycle now and nobody else', function () {
    Http::fake(fn () => Http::response(['data' => [], 'has_more' => false, 'deleted' => 0]));

    actingAsSecurity();
    $this->postJson('/api/sync/run')->assertForbidden();

    actingAsSupervisor();
    $this->postJson('/api/sync/run')->assertOk()->assertJsonPath('level', 'green');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/sync/pre-registrations'));
});
