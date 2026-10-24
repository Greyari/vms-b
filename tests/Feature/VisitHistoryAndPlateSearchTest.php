<?php

use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\Staff;
use App\Models\VehicleCard;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;

beforeEach(function () {
    actingAsAdmin();
});

it('normalizes vehicle plates and supports tolerant plate search (case, spaces, hyphens, partial)', function () {
    $visitor = Visitor::factory()->create(['name' => 'Hendra Wijaya']);
    $location = Location::factory()->create(['name' => 'JETTY 01']);
    $staff = Staff::factory()->create(['name' => 'Capt. Rahmat']);

    $visit = Visit::factory()->create([
        'visitor_id' => $visitor->id,
        'location_id' => $location->id,
        'location_name' => $location->name,
        'staff_id' => $staff->id,
        'host_name' => $staff->name,
        'purpose' => 'Cargo Delivery',
        'vehicle_no' => 'BP 1234 XY',
        'checked_out_at' => null,
    ]);

    expect($visit->fresh()->plate_normalized)->toBe('BP1234XY');

    foreach (['bp1234xy', 'BP 1234 XY', 'bp-1234-xy', '1234'] as $query) {
        $response = $this->getJson('/api/visits?status=all&search='.urlencode($query))
            ->assertOk();

        expect($response->json('total'))->toBe(1);
        expect($response->json('data.0.id'))->toBe($visit->id);
        expect($response->json('data.0.vehicle_no'))->toBe('BP 1234 XY');
    }
});

it('returns status tab counts and filters visits by active, completed, and all', function () {
    $activeVisit = Visit::factory()->create(['checked_out_at' => null]);
    $completedVisit = Visit::factory()->create([
        'checked_out_at' => now(),
        'time_out' => now()->toTimeString(),
    ]);

    $resActive = $this->getJson('/api/visits?status=active')->assertOk();
    expect($resActive->json('total'))->toBe(1);
    expect($resActive->json('data.0.id'))->toBe($activeVisit->id);
    expect($resActive->json('counts'))->toEqual([
        'active' => 1,
        'completed' => 1,
        'all' => 2,
    ]);

    $resCompleted = $this->getJson('/api/visits?status=completed')->assertOk();
    expect($resCompleted->json('total'))->toBe(1);
    expect($resCompleted->json('data.0.id'))->toBe($completedVisit->id);

    $resAll = $this->getJson('/api/visits?status=all')->assertOk();
    expect($resAll->json('total'))->toBe(2);
});

it('performs bulk check-out in a single transaction, releasing visitor and vehicle cards and recording audit fields', function () {
    $admin = adminUser();

    $card1 = VisitorCard::factory()->inUse()->create(['card_no' => 'VIS-101']);
    $vehCard1 = VehicleCard::factory()->inUse()->create(['card_no' => 'VEH-201']);
    $visit1 = Visit::factory()->create([
        'vehicle_card_id' => $vehCard1->id,
        'checked_out_at' => null,
    ]);
    $visit1->visitorCards()->sync([$card1->id]);

    $card2 = VisitorCard::factory()->inUse()->create(['card_no' => 'VIS-102']);
    $visit2 = Visit::factory()->create([
        'checked_out_at' => null,
    ]);
    $visit2->visitorCards()->sync([$card2->id]);

    $response = $this->postJson('/api/visits/bulk-checkout', [
        'visit_ids' => [$visit1->id, $visit2->id],
    ])->assertOk();

    expect($response->json('checked_out_count'))->toBe(2);

    expect($card1->fresh()->status)->toBe('available');
    expect($vehCard1->fresh()->status)->toBe('available');
    expect($card2->fresh()->status)->toBe('available');

    $v1Fresh = $visit1->fresh();
    expect($v1Fresh->checked_out_at)->not->toBeNull();
    expect($v1Fresh->checked_out_by)->toBe($admin->id);
    expect($v1Fresh->checked_out_by_name)->toBe($admin->name);
});

it('logs reprint activity and returns is_completed flag when printing a pass', function () {
    $completedVisit = Visit::factory()->create([
        'checked_out_at' => now(),
        'time_out' => now()->toTimeString(),
    ]);

    $response = $this->getJson("/api/visits/{$completedVisit->id}/print")
        ->assertOk();

    expect($response->json('is_completed'))->toBeTrue();

    $logExists = ActivityLog::where('action', 'reprinted')
        ->where('subject_id', $completedVisit->id)
        ->exists();
    expect($logExists)->toBeTrue();
});
