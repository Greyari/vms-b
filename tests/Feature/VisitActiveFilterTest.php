<?php

use App\Models\Location;
use App\Models\Staff;
use App\Models\VehicleCard;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use Carbon\Carbon;

beforeEach(function () {
    actingAsAdmin();
});

function makeActiveVisit(array $attributes = []): Visit
{
    $visitor = Visitor::factory()->create();
    $staff = Staff::factory()->create();
    $location = Location::factory()->create();
    $card = VisitorCard::factory()->inUse()->create();

    $visit = Visit::factory()->create(array_merge([
        'visitor_id' => $visitor->id,
        'staff_id' => $staff->id,
        'location_id' => $location->id,
        'location_name' => $location->name,
        'host_name' => $staff->name,
        'visitor_company' => $visitor->company,
        'purpose' => 'Routine Operation',
        'date' => now()->toDateString(),
        'time_in' => now()->toTimeString(),
        'checked_out_at' => null,
    ], $attributes));

    $visit->visitorCards()->sync([$card->id]);

    return $visit;
}

it('calculates days_open accurately for open and checked out visits', function () {
    $now = Carbon::parse('2026-09-24 10:00:00');
    Carbon::setTestNow($now);

    // Visit opened 3 days ago, still open
    $openVisit = makeActiveVisit([
        'date' => '2026-09-21',
        'checked_out_at' => null,
    ]);

    // Visit opened 5 days ago, checked out 1 day ago (duration 4 days)
    $closedVisit = makeActiveVisit([
        'date' => '2026-09-19',
        'checked_out_at' => Carbon::parse('2026-09-23 15:00:00'),
    ]);

    $responseOpen = $this->getJson("/api/visits/{$openVisit->id}/print")->assertOk();
    expect($responseOpen->json('days_open'))->toBe(3);

    $responseClosed = $this->getJson("/api/visits/{$closedVisit->id}/print")->assertOk();
    expect($responseClosed->json('days_open'))->toBe(4);

    Carbon::setTestNow();
});

it('filters active visits by date range (date_from and date_to)', function () {
    makeActiveVisit(['date' => '2026-09-10']);
    $targetVisit = makeActiveVisit(['date' => '2026-09-15']);
    makeActiveVisit(['date' => '2026-09-20']);

    $response = $this->getJson('/api/visits/active?date_from=2026-09-14&date_to=2026-09-16')
        ->assertOk();

    expect($response->json())->toHaveCount(1);
    expect($response->json('0.id'))->toBe($targetVisit->id);
});

it('filters active visits by ticket_number or legacy_registration_no', function () {
    $visit1 = makeActiveVisit(['ticket_number' => '26/09/00042']);
    $visit2 = makeActiveVisit(['legacy_registration_no' => 'REG-LEGACY-999']);
    makeActiveVisit(['ticket_number' => '26/09/00088']);

    $responseTicket = $this->getJson('/api/visits/active?ticket_number=00042')->assertOk();
    expect($responseTicket->json())->toHaveCount(1);
    expect($responseTicket->json('0.id'))->toBe($visit1->id);

    $responseLegacy = $this->getJson('/api/visits/active?ticket_number=LEGACY-999')->assertOk();
    expect($responseLegacy->json())->toHaveCount(1);
    expect($responseLegacy->json('0.id'))->toBe($visit2->id);
});

it('filters active visits by visitor_name', function () {
    $visitor1 = Visitor::factory()->create(['name' => 'Alexander Supertramp']);
    $visit1 = makeActiveVisit(['visitor_id' => $visitor1->id]);

    $visitor2 = Visitor::factory()->create(['name' => 'Budi Santoso']);
    makeActiveVisit(['visitor_id' => $visitor2->id]);

    $response = $this->getJson('/api/visits/active?visitor_name=supertramp')->assertOk();
    expect($response->json())->toHaveCount(1);
    expect($response->json('0.id'))->toBe($visit1->id);
});

it('filters active visits by visitor card or vehicle card number', function () {
    $card = VisitorCard::factory()->inUse()->create(['card_no' => 'V-CARD-777']);
    $visitWithVisitorCard = makeActiveVisit();
    $visitWithVisitorCard->visitorCards()->sync([$card->id]);

    $vehicleCard = VehicleCard::factory()->inUse()->create(['card_no' => 'MOBIL-888']);
    $visitWithVehicleCard = makeActiveVisit(['vehicle_card_id' => $vehicleCard->id]);

    makeActiveVisit(); // decoy

    $responseVisitorCard = $this->getJson('/api/visits/active?card_no=card-777')->assertOk();
    expect($responseVisitorCard->json())->toHaveCount(1);
    expect($responseVisitorCard->json('0.id'))->toBe($visitWithVisitorCard->id);

    $responseVehicleCard = $this->getJson('/api/visits/active?card_no=mobil-888')->assertOk();
    expect($responseVehicleCard->json())->toHaveCount(1);
    expect($responseVehicleCard->json('0.id'))->toBe($visitWithVehicleCard->id);
});

it('filters active visits by company name', function () {
    $visit1 = makeActiveVisit(['visitor_company' => 'PT Krakatau Marine']);
    $visitor2 = Visitor::factory()->create(['company' => 'PT Barata Indonesia']);
    $visit2 = makeActiveVisit(['visitor_id' => $visitor2->id, 'visitor_company' => null]);
    makeActiveVisit(['visitor_company' => 'Other Corp']);

    $response1 = $this->getJson('/api/visits/active?company=krakatau')->assertOk();
    expect($response1->json())->toHaveCount(1);
    expect($response1->json('0.id'))->toBe($visit1->id);

    $response2 = $this->getJson('/api/visits/active?company=barata')->assertOk();
    expect($response2->json())->toHaveCount(1);
    expect($response2->json('0.id'))->toBe($visit2->id);
});

it('paginates active visits when per_page parameter is passed', function () {
    makeActiveVisit();
    makeActiveVisit();
    makeActiveVisit();

    $response = $this->getJson('/api/visits/active?per_page=2')->assertOk();

    $response->assertJsonStructure([
        'current_page',
        'data',
        'total',
        'per_page',
    ]);
    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('total'))->toBe(3);
    expect($response->json('per_page'))->toBe(2);
});
