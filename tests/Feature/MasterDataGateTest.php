<?php

use App\Models\Staff;
use App\Models\Visit;
use App\Models\VisitorCard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    actingAsAdmin();
    Cache::flush(); // never reuse a cached svc token between tests
    config([
        'services.master_data.enabled' => true,
        'services.master_data.password' => 'svc-secret',
        'services.master_data.base_url' => 'http://master.test/api/v1',
        'services.master_data.strict' => false,
    ]);
});

function gateVisitPayload(): array
{
    $staff = Staff::factory()->create();
    $card = VisitorCard::factory()->create();

    return [
        'id_type' => 'ktp',
        'id_number' => '9210000000000001',
        'name' => 'Gate Visitor',
        'staff_id' => $staff->id,
        'card_ids' => [$card->id],
        'purpose' => 'Meeting',
    ];
}

it('blocks a visit when Master Data reports the person blacklisted (422 + master reason)', function () {
    Http::fake([
        '*/auth/login' => Http::response(['accessToken' => 'tok-123'], 200),
        '*/people/lookup*' => Http::response([
            'data' => ['fullName' => 'Gate Visitor', 'isBlacklisted' => true, 'blacklistReason' => 'Central ban'],
        ], 200),
    ]);

    $response = $this->postJson('/api/visits', gateVisitPayload());

    $response->assertStatus(422);
    expect($response->json('reason'))->toBe('Central ban');
    expect(Visit::count())->toBe(0);
});

it('returns 503 when the master check fails in strict mode', function () {
    config(['services.master_data.strict' => true]);
    Http::fake([
        '*/auth/login' => Http::response(['accessToken' => 'tok-123'], 200),
        '*/people/lookup*' => Http::response('upstream error', 500),
    ]);

    $this->postJson('/api/visits', gateVisitPayload())->assertStatus(503);
    expect(Visit::count())->toBe(0);
});

it('fails open (201) when the master check fails and strict mode is off', function () {
    config(['services.master_data.strict' => false]);
    Http::fake([
        '*/auth/login' => Http::response(['accessToken' => 'tok-123'], 200),
        '*/people/lookup*' => Http::response('upstream error', 500),
    ]);

    $this->postJson('/api/visits', gateVisitPayload())->assertStatus(201);
    expect(Visit::count())->toBe(1);
});

it('never contacts Master Data when the integration is disabled', function () {
    config(['services.master_data.enabled' => false]);
    Http::fake();

    $this->postJson('/api/visits', gateVisitPayload())->assertStatus(201);

    Http::assertNothingSent();
});
