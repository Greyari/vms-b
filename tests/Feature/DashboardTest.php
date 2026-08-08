<?php

use App\Models\Staff;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VehicleCard;
use App\Models\VisitorCard;

beforeEach(function () {
    actingAsAdmin();
});

it('returns dashboard stats, card counts and a 7-day chart with correct values', function () {
    $staff = Staff::factory()->create();

    // 3 active visitors + 1 blacklisted
    $v1 = Visitor::factory()->create();
    $v2 = Visitor::factory()->create();
    $v3 = Visitor::factory()->create();
    Visitor::factory()->blacklisted()->create();

    // 5 visitor cards, 2 in use
    VisitorCard::factory()->count(3)->create();
    VisitorCard::factory()->count(2)->inUse()->create();

    // 4 vehicle cards, 1 in use
    VehicleCard::factory()->count(3)->create();
    VehicleCard::factory()->inUse()->create();

    // 2 active visits + 1 checked-out visit, all dated today
    Visit::factory()->create(['visitor_id' => $v1->id, 'staff_id' => $staff->id]);
    Visit::factory()->create(['visitor_id' => $v2->id, 'staff_id' => $staff->id]);
    Visit::factory()->checkedOut()->create(['visitor_id' => $v3->id, 'staff_id' => $staff->id]);

    $response = $this->getJson('/api/dashboard');

    $response->assertOk()->assertJsonStructure([
        'stats' => ['active_visits', 'month_visits', 'total_visitors', 'blacklisted_visitors'],
        'cards' => ['total', 'available', 'in_use'],
        'vehicle_cards' => ['total', 'available', 'in_use'],
        'chart',
        'recent_visits',
    ]);

    expect($response->json('stats.active_visits'))->toBe(2);
    expect($response->json('stats.month_visits'))->toBe(3);
    expect($response->json('stats.total_visitors'))->toBe(3);
    expect($response->json('stats.blacklisted_visitors'))->toBe(1);

    expect($response->json('cards.total'))->toBe(5);
    expect($response->json('cards.available'))->toBe(3);
    expect($response->json('cards.in_use'))->toBe(2);

    expect($response->json('vehicle_cards.total'))->toBe(4);
    expect($response->json('vehicle_cards.available'))->toBe(3);
    expect($response->json('vehicle_cards.in_use'))->toBe(1);

    expect($response->json('chart'))->toHaveCount(7);
    expect(count($response->json('recent_visits')))->toBeLessThanOrEqual(5);
    expect(count($response->json('recent_visits')))->toBe(2);
});

it('returns a null-safe dashboard on an empty database (B9)', function () {
    $response = $this->getJson('/api/dashboard');

    $response->assertOk();
    expect($response->json('stats.active_visits'))->toBe(0);
    expect($response->json('stats.total_visitors'))->toBe(0);
    expect($response->json('chart'))->toHaveCount(7);
    expect($response->json('recent_visits'))->toBe([]);
});
