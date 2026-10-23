<?php

use App\Models\Location;
use App\Models\Staff;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use Carbon\Carbon;

beforeEach(function () {
    actingAsAdmin();
});

function createReprintVisit(array $attributes = []): Visit
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
        'purpose' => 'Dock Inspection',
        'date' => now()->toDateString(),
        'time_in' => now()->toTimeString(),
        'checked_out_at' => null,
    ], $attributes));

    $visit->visitorCards()->sync([$card->id]);

    return $visit;
}

it('lists visits for reprint defaulting to today', function () {
    $now = Carbon::parse('2026-09-24 14:00:00');
    Carbon::setTestNow($now);

    $todayVisit = createReprintVisit(['date' => '2026-09-24']);
    $yesterdayVisit = createReprintVisit(['date' => '2026-09-23']);

    $response = $this->getJson('/api/visits/reprint')->assertOk();

    $response->assertJsonStructure(['data', 'current_page', 'total', 'per_page']);
    expect($response->json('total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($todayVisit->id);

    Carbon::setTestNow();
});

it('filters reprint visits by custom date', function () {
    $targetVisit = createReprintVisit(['date' => '2026-09-20']);
    createReprintVisit(['date' => '2026-09-24']);

    $response = $this->getJson('/api/visits/reprint?date=2026-09-20')->assertOk();

    expect($response->json('total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($targetVisit->id);
});

it('filters reprint visits by visitor name and normalized id_number', function () {
    $visitor1 = Visitor::factory()->create([
        'name' => 'Wayan Sukadana',
        'id_number' => '5171010000000001',
    ]);
    $visit1 = createReprintVisit(['visitor_id' => $visitor1->id, 'date' => '2026-09-24']);

    $visitor2 = Visitor::factory()->create([
        'name' => 'Made Artha',
        'id_number' => '5171010000000002',
    ]);
    createReprintVisit(['visitor_id' => $visitor2->id, 'date' => '2026-09-24']);

    // Filter by name
    $responseName = $this->getJson('/api/visits/reprint?date=2026-09-24&name=sukadana')->assertOk();
    expect($responseName->json('total'))->toBe(1);
    expect($responseName->json('data.0.id'))->toBe($visit1->id);

    // Filter by punctuated id_number
    $responseId = $this->getJson('/api/visits/reprint?date=2026-09-24&id_number=5171-0100-0000-0001')->assertOk();
    expect($responseId->json('total'))->toBe(1);
    expect($responseId->json('data.0.id'))->toBe($visit1->id);
});

it('caps per_page pagination at 1000 for reprint list', function () {
    createReprintVisit(['date' => '2026-09-24']);

    $response = $this->getJson('/api/visits/reprint?date=2026-09-24&per_page=5000')->assertOk();
    expect($response->json('per_page'))->toBe(1000);
});

it('includes printed_by user name in pass print response', function () {
    $admin = adminUser();
    actingAsAdmin();

    $visit = createReprintVisit();

    $response = $this->getJson("/api/visits/{$visit->id}/print")->assertOk();

    expect($response->json('printed_by'))->toBe($admin->name);
    expect($response->json('id'))->toBe($visit->id);
});

it('requires view_visits permission to access reprint list', function () {
    $userWithoutPermission = User::factory()->create();
    actingAsUser($userWithoutPermission);

    $this->getJson('/api/visits/reprint')->assertStatus(403);
});
