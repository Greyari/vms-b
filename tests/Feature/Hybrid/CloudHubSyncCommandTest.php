<?php

use App\Enums\BlacklistReasonCategory;
use App\Enums\PreRegistrationStatus;
use App\Enums\SyncKey;
use App\Models\GlobalBlacklist;
use App\Models\GlobalBlacklistOutbox;
use App\Models\Location;
use App\Models\PreRegistration;
use App\Models\SyncStateLog;
use App\Models\Visit;
use App\Models\Visitor;
use App\Support\YardSignature;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * docs/vms-hybrid-architecture-spec.md §5.4: one sync cycle pushes the local outbox, mirrors global
 * blacklist changes by sync_seq, pulls and acknowledges pre-registrations, and never lets a hub problem
 * disturb the gate (decisions BE-6, PE-3, PE-4, SEC-3, OPS-7).
 */

const GATE_SYNC_TEST_SECRET = 'test-only-not-a-real-secret-gate';

beforeEach(function () {
    config(['vms.sync' => array_merge(config('vms.sync', []), [
        'enabled' => true,
        'yard_code' => 'CIS',
        'hub_url' => 'https://hub.test/api/v1',
        'key_id' => '2026a',
        'secret' => GATE_SYNC_TEST_SECRET,
    ])]);
    $this->travelTo(Carbon::parse('2026-10-01 09:00:00', 'Asia/Jakarta'));
});

/** @param array<string, mixed> $overrides */
function hubEntry(array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::uuid7(),
        'id_type' => 'ktp',
        'id_number' => '3210000000000001',
        'name' => 'Budi Santoso',
        'reason_category' => 'k3_violation',
        'registered_by_yard' => 'ASL',
        'is_active' => true,
        'sync_seq' => 1,
    ], $overrides);
}

/** @param array<string, mixed> $overrides */
function hubPreRegistration(array $overrides = []): array
{
    return array_merge([
        'token' => 'PR-AAAA-BBBB-CCCC',
        'id_type' => 'ktp',
        'id_number' => '3210000000000009',
        'name' => 'Siti Aminah',
        'company' => 'PT Contoh',
        'phone' => '081234567890',
        'gender' => null,
        'purpose' => 'Survey',
        'host_name' => 'Pak Andi',
        'host_department' => null,
        'scheduled_date' => '2026-10-01',
        'vehicle_no' => null,
    ], $overrides);
}

/**
 * A scripted hub: "METHOD /path" => responses served in order. Anything unscripted answers an empty
 * page, which is what an idle hub says.
 *
 * @param  array<string, list<mixed>>  $script
 */
function fakeHub(array $script = []): void
{
    Http::fake(function (Request $request) use (&$script) {
        $key = $request->method().' '.Str::after((string) parse_url($request->url(), PHP_URL_PATH), '/api/v1');

        return empty($script[$key])
            ? Http::response(['data' => [], 'has_more' => false, 'deleted' => 0])
            : array_shift($script[$key]);
    });
}

function signedByThisYard(Request $request): bool
{
    $header = fn (string $name): string => (string) ($request->header($name)[0] ?? '');
    $canonical = YardSignature::canonical(
        $header('X-Yard-Code'), $header('X-Yard-Key-Id'), $header('X-Yard-Timestamp'), $header('X-Yard-Nonce'),
        $request->method(), (string) parse_url($request->url(), PHP_URL_PATH), (string) parse_url($request->url(), PHP_URL_QUERY),
        $request->body(),
    );

    return $header('X-Yard-Code') === 'CIS'
        && YardSignature::isValidTimestamp($header('X-Yard-Timestamp'))
        && hash_equals(YardSignature::sign($canonical, GATE_SYNC_TEST_SECRET), $header('X-Yard-Signature'));
}

function syncState(SyncKey $key): ?SyncStateLog
{
    return SyncStateLog::find($key->value);
}

function queueOutbox(string $action, string $number, int $minutesAgo = 0): GlobalBlacklistOutbox
{
    $row = GlobalBlacklistOutbox::create([
        'action' => $action,
        'id_type' => 'ktp',
        'id_number' => $number,
        'name' => 'Orang '.$number,
        'reason_category' => $action === 'add' ? BlacklistReasonCategory::Misconduct : null,
        'local_note' => 'Catatan petugas yang tidak boleh keluar',
    ]);
    $row->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->saveQuietly();

    return $row;
}

it('does nothing at all while sync is switched off', function () {
    config(['vms.sync.enabled' => false]);
    fakeHub();

    $this->artisan('vms:sync-cloud-hub')->assertSuccessful();

    Http::assertNothingSent();
    expect(SyncStateLog::count())->toBe(0);
});

it('skips the cycle while a deploy holds the gate in maintenance mode', function () {
    fakeHub();
    app()->maintenanceMode()->activate([]);

    try {
        $this->artisan('vms:sync-cloud-hub')
            ->expectsOutputToContain('maintenance mode')
            ->assertSuccessful();
    } finally {
        app()->maintenanceMode()->deactivate();
    }

    Http::assertNothingSent();
    expect(SyncStateLog::count())->toBe(0);
});

it('reports a missing hub setting without failing the gate', function () {
    config(['vms.sync.hub_url' => '']);
    fakeHub();

    $this->artisan('vms:sync-cloud-hub')->assertSuccessful();

    Http::assertNothingSent();
    expect(syncState(SyncKey::BlacklistPull))
        ->status->toBe('error')
        ->last_error->toContain('VMS_CLOUD_HUB_URL');
});

it('signs every request with the yard key and a UTC timestamp', function () {
    fakeHub();

    $this->artisan('vms:sync-cloud-hub')->assertSuccessful();

    expect(Http::recorded())->not->toBeEmpty();
    Http::assertSent(fn (Request $request) => $request->hasHeader('X-Yard-Signature'));
    foreach (Http::recorded() as [$request]) {
        expect(signedByThisYard($request))->toBeTrue($request->method().' '.$request->url());
    }
});

it('mirrors global blacklist changes page by page and remembers the cursor', function () {
    $first = hubEntry(['sync_seq' => 1]);
    fakeHub(['GET /sync/blacklist' => [
        Http::response(['data' => [$first, hubEntry(['id_number' => '3210000000000002', 'sync_seq' => 2])], 'has_more' => true]),
        Http::response(['data' => [array_merge($first, ['is_active' => false, 'sync_seq' => 3])], 'has_more' => false]),
    ]]);

    $this->artisan('vms:sync-cloud-hub')->assertSuccessful();

    expect(GlobalBlacklist::count())->toBe(2)
        ->and(GlobalBlacklist::firstWhere('hub_id', $first['id']))
        ->is_active->toBeFalse()
        ->sync_seq->toBe(3)
        ->and(syncState(SyncKey::BlacklistPull))
        ->last_seq->toBe(3)
        ->status->toBe('ok')
        ->records_synced->toBe(3);

    $cursors = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $r) => str_contains($r->url(), '/sync/blacklist'))
        ->map(fn (Request $r) => $r->data()['after_seq'] ?? null)
        ->values()->all();
    expect($cursors)->toBe(['0', '2']);

    // The next cycle starts where this one stopped.
    fakeHub();
    $this->artisan('vms:sync-cloud-hub')->assertSuccessful();
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/sync/blacklist') && ($r->data()['after_seq'] ?? null) === '3');
});

it('takes a 404 from the hub as the global blacklist being switched off, not as a failure', function () {
    fakeHub(['GET /sync/blacklist' => [Http::response(['message' => ''], 404)]]);

    $this->artisan('vms:sync-cloud-hub')->assertSuccessful();

    expect(syncState(SyncKey::BlacklistPull))
        ->status->toBe('disabled')
        ->last_error->toBeNull();
});

it('pulls pre-registrations without overwriting local status, then acknowledges them', function () {
    PreRegistration::create(array_merge(hubPreRegistration(['token' => 'PR-DONE-DONE-DONE']), [
        'status' => PreRegistrationStatus::CheckedIn, 'pulled_at' => now()->subDay(),
    ]));
    fakeHub(['GET /sync/pre-registrations' => [Http::response(['data' => [
        hubPreRegistration(['token' => 'PR-DONE-DONE-DONE', 'name' => 'Changed']),
        hubPreRegistration(),
    ]])]]);

    $this->artisan('vms:sync-cloud-hub')->assertSuccessful();

    expect(PreRegistration::firstWhere('token', 'PR-DONE-DONE-DONE')->status)->toBe(PreRegistrationStatus::CheckedIn)
        ->and(PreRegistration::firstWhere('token', 'PR-AAAA-BBBB-CCCC'))
        ->status->toBe(PreRegistrationStatus::Pending)
        ->gender->toBeNull()
        ->name->toBe('Siti Aminah')
        ->and(syncState(SyncKey::PreRegistrations))->status->toBe('ok')->records_synced->toBe(2);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && str_ends_with($r->url(), '/sync/pre-registrations/ack')
        && $r->data() === ['tokens' => ['PR-DONE-DONE-DONE', 'PR-AAAA-BBBB-CCCC']]);
});

it('links a walk-in who arrived before the pre-registration was pulled', function () {
    $visitor = Visitor::factory()->create(['id_type' => 'ktp', 'id_number' => '3210000000000009']);
    $visit = Visit::factory()->create(['visitor_id' => $visitor->id, 'date' => '2026-10-01', 'location_id' => Location::factory()->create()->id]);
    fakeHub(['GET /sync/pre-registrations' => [Http::response(['data' => [hubPreRegistration()]])]]);

    $this->artisan('vms:sync-cloud-hub')->assertSuccessful();

    expect(PreRegistration::firstWhere('token', 'PR-AAAA-BBBB-CCCC'))
        ->status->toBe(PreRegistrationStatus::CheckedIn)
        ->visit_id->toBe($visit->id);
});

it('expires pending pre-registrations whose date has passed', function () {
    PreRegistration::create(array_merge(hubPreRegistration(['token' => 'PR-OLDD-OLDD-OLDD', 'scheduled_date' => '2026-09-30']), ['pulled_at' => now()]));
    PreRegistration::create(array_merge(hubPreRegistration(['scheduled_date' => '2026-10-01']), ['pulled_at' => now()]));
    fakeHub();

    $this->artisan('vms:sync-cloud-hub')->assertSuccessful();

    expect(PreRegistration::firstWhere('token', 'PR-OLDD-OLDD-OLDD')->status)->toBe(PreRegistrationStatus::Expired)
        ->and(PreRegistration::firstWhere('token', 'PR-AAAA-BBBB-CCCC')->status)->toBe(PreRegistrationStatus::Pending);
});

it('sends queued requests oldest first, without the local note, and stops at the first hub failure', function () {
    $first = queueOutbox('add', '1111', 3);
    $second = queueOutbox('add', '2222', 2);
    $third = queueOutbox('revoke', '3333', 1);
    fakeHub(['POST /sync/blacklist' => [
        Http::response(['data' => hubEntry(['id_number' => '1111'])], 201),
        Http::response(['message' => 'Server Error'], 500),
    ]]);

    $this->artisan('vms:sync-cloud-hub')->assertSuccessful();

    expect($first->fresh())->sent_at->not->toBeNull()->last_error->toBeNull()
        ->and($second->fresh())->sent_at->toBeNull()->attempts->toBe(1)->last_error->toContain('500')
        ->and($third->fresh())->sent_at->toBeNull()->attempts->toBe(0)
        ->and(syncState(SyncKey::BlacklistPush))->status->toBe('error');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/sync/blacklist')
        && $r->data()['client_ref'] === $first->id
        && $r->data()['action'] === 'add'
        && $r->data()['reason_category'] === 'misconduct'
        && ! array_key_exists('local_note', $r->data()));
});

it('records a refusal from the hub and moves on to the next request', function (int $status) {
    $refused = queueOutbox('revoke', '1111', 2);
    $next = queueOutbox('add', '2222', 1);
    fakeHub(['POST /sync/blacklist' => [
        Http::response(['message' => 'Only the yard that registered this entry can revoke it.'], $status),
        Http::response(['data' => hubEntry(['id_number' => '2222'])], 201),
    ]]);

    $this->artisan('vms:sync-cloud-hub')->assertSuccessful();

    expect($refused->fresh())->sent_at->not->toBeNull()->last_error->toContain('Only the yard')
        ->and($next->fresh())->sent_at->not->toBeNull()->last_error->toBeNull();
})->with([403, 404, 422]);

it('takes a 409 as done: the number is already on the global list', function () {
    $row = queueOutbox('add', '1111');
    fakeHub(['POST /sync/blacklist' => [Http::response([
        'message' => 'This number is already on the global blacklist, registered by ASL.',
        'data' => hubEntry(['id_number' => '1111']),
    ], 409)]]);

    $this->artisan('vms:sync-cloud-hub')->assertSuccessful();

    expect($row->fresh())->sent_at->not->toBeNull()->last_error->toContain('registered by ASL');
});

it('keeps the gate running when the hub cannot be reached', function () {
    queueOutbox('add', '1111');
    Http::fake(fn () => Http::failedConnection('Could not resolve host: hub.test'));

    $this->artisan('vms:sync-cloud-hub')->assertSuccessful();

    foreach ([SyncKey::BlacklistPush, SyncKey::BlacklistPull, SyncKey::PreRegistrations] as $key) {
        expect(syncState($key))->status->toBe('error')->last_success_at->toBeNull();
    }
    expect(syncState(SyncKey::BlacklistPull)->last_error)->toContain('Could not resolve host');
});

it('runs one cycle at a time', function () {
    fakeHub();
    $lock = Cache::lock('vms:sync-cloud-hub', 60);
    expect($lock->get())->toBeTrue();

    try {
        $this->artisan('vms:sync-cloud-hub')->assertSuccessful();
    } finally {
        $lock->release();
    }

    Http::assertNothingSent();
});
