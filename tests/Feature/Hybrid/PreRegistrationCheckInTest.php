<?php

use App\Enums\PreRegistrationStatus;
use App\Models\Location;
use App\Models\PreRegistration;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorCard;
use Illuminate\Support\Carbon;

/*
 * Hybrid spec §6.2 (decisions FE-4a, PE-4, BE-2): the officer scans the QR code — it carries only the
 * token — and the form fills from the local mirror, with no internet. After the visit is recorded the
 * pre-registration becomes checked_in; a walk-in with the same number on the scheduled day is linked too.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-01 09:00:00', 'Asia/Jakarta'));
});

function pulledPreRegistration(array $overrides = []): PreRegistration
{
    return PreRegistration::create(array_merge([
        'token' => 'PR-AAAA-BBBB-CCCC',
        'id_type' => 'ktp',
        'id_number' => '3210000000000009',
        'name' => 'Siti Aminah',
        'company' => 'PT Contoh',
        'phone' => '081234567890',
        'purpose' => 'Survey',
        'host_name' => 'Pak Andi',
        'scheduled_date' => '2026-10-01',
        'vehicle_no' => 'BP 1234 XY',
        'pulled_at' => now(),
    ], $overrides));
}

/** @param array<string, mixed> $overrides */
function preRegisteredCheckIn(array $overrides = []): array
{
    return array_merge([
        'id_type' => 'ktp',
        'id_number' => '3210000000000009',
        'name' => 'Siti Aminah',
        'gender' => 'F',
        'host_name' => 'Pak Andi',
        'location_id' => Location::factory()->create()->id,
        'card_ids' => [VisitorCard::factory()->create()->id],
        'purpose' => 'Survey',
    ], $overrides);
}

it('finds a pre-registration by its scanned token, whatever the case', function () {
    actingAsSecurity();
    pulledPreRegistration();

    $this->getJson('/api/pre-registrations/pr-aaaa-bbbb-cccc')
        ->assertOk()
        ->assertJsonPath('data.token', 'PR-AAAA-BBBB-CCCC')
        ->assertJsonPath('data.name', 'Siti Aminah')
        ->assertJsonPath('data.id_number', '3210000000000009')
        ->assertJsonPath('data.gender', null)
        ->assertJsonPath('data.scheduled_date', '2026-10-01')
        ->assertJsonPath('data.status', 'pending');
});

it('answers 404 for a token this gate has not pulled yet', function () {
    actingAsSecurity();

    $this->getJson('/api/pre-registrations/PR-ZZZZ-ZZZZ-ZZZZ')->assertNotFound();
});

it('shows a pre-registration whose day has passed as expired', function () {
    actingAsSecurity();
    pulledPreRegistration(['scheduled_date' => '2026-09-30']);

    $this->getJson('/api/pre-registrations/PR-AAAA-BBBB-CCCC')->assertOk()->assertJsonPath('data.status', 'expired');
});

it('marks the pre-registration checked in once the visit is recorded', function () {
    actingAsSecurity();
    $pre = pulledPreRegistration();

    $response = $this->postJson('/api/visits', preRegisteredCheckIn(['pre_registration_token' => 'pr-aaaa-bbbb-cccc']))->assertCreated();

    expect($pre->fresh())
        ->status->toBe(PreRegistrationStatus::CheckedIn)
        ->visit_id->toBe($response->json('visit.id'));
});

it('refuses a pre-registration that was already used or has expired', function (array $state, string $message) {
    actingAsSecurity();
    pulledPreRegistration($state);

    $this->postJson('/api/visits', preRegisteredCheckIn(['pre_registration_token' => 'PR-AAAA-BBBB-CCCC']))
        ->assertUnprocessable()
        ->assertJsonPath('message', $message);

    expect(Visitor::count())->toBe(0);
})->with([
    'already used' => [['status' => PreRegistrationStatus::CheckedIn], 'This pre-registration has already been used for a check-in.'],
    'day passed' => [['scheduled_date' => '2026-09-30'], 'This pre-registration has expired.'],
]);

it('links a walk-in to a pending pre-registration with the same number for today', function () {
    actingAsSecurity();
    $today = pulledPreRegistration();
    $tomorrow = pulledPreRegistration(['token' => 'PR-TMRW-TMRW-TMRW', 'scheduled_date' => '2026-10-02']);

    $response = $this->postJson('/api/visits', preRegisteredCheckIn(['id_number' => '3210-0000 0000 0009']))->assertCreated();

    expect($today->fresh())->status->toBe(PreRegistrationStatus::CheckedIn)->visit_id->toBe($response->json('visit.id'))
        ->and($tomorrow->fresh()->status)->toBe(PreRegistrationStatus::Pending);
});

it('requires permission to register visits', function () {
    actingAsUser(User::factory()->create());
    pulledPreRegistration();

    $this->getJson('/api/pre-registrations/PR-AAAA-BBBB-CCCC')->assertForbidden();
});
