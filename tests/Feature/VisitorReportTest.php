<?php

use App\Enums\CardType;
use App\Enums\VisitorType;
use App\Models\Location;
use App\Models\Staff;
use App\Models\VehicleCard;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;

it('forbids unauthenticated user from accessing visitor report', function () {
    $this->getJson('/api/reports/visits')->assertUnauthorized();
    $this->getJson('/api/visits/history')->assertUnauthorized();
});

it('forbids security role from accessing visitor report and history alias', function () {
    actingAsSecurity();

    $this->getJson('/api/reports/visits')->assertForbidden();
    $this->getJson('/api/visits/history')->assertForbidden();
});

it('allows supervisor and administrator to access visitor report', function () {
    actingAsSupervisor();
    $this->getJson('/api/reports/visits')->assertOk();
    $this->getJson('/api/visits/history')->assertOk();

    actingAsAdmin();
    $this->getJson('/api/reports/visits')->assertOk();
});

it('filters visits by date range and time range', function () {
    actingAsSupervisor();

    $v1 = Visit::factory()->create([
        'date' => '2026-09-10',
        'time_in' => '08:30:00',
    ]);
    $v2 = Visit::factory()->create([
        'date' => '2026-09-15',
        'time_in' => '14:00:00',
    ]);
    $v3 = Visit::factory()->create([
        'date' => '2026-09-20',
        'time_in' => '10:00:00',
    ]);

    // Filter date_from and date_to
    $response = $this->getJson('/api/reports/visits?date_from=2026-09-12&date_to=2026-09-18')
        ->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($v2->id);
    expect($ids)->not->toContain($v1->id, $v3->id);

    // Filter time_from and time_to
    $timeResponse = $this->getJson('/api/reports/visits?time_from=09:00&time_to=12:00')
        ->assertOk();
    $timeIds = collect($timeResponse->json('data'))->pluck('id')->all();
    expect($timeIds)->toContain($v3->id);
    expect($timeIds)->not->toContain($v1->id, $v2->id);
});

it('filters visits by visitor_no range', function () {
    actingAsSupervisor();

    $visitor100 = Visitor::factory()->create(['visitor_no' => 1000001]);
    $visitor200 = Visitor::factory()->create(['visitor_no' => 1000050]);
    $visitor300 = Visitor::factory()->create(['visitor_no' => 1000100]);

    $v1 = Visit::factory()->create(['visitor_id' => $visitor100->id]);
    $v2 = Visit::factory()->create(['visitor_id' => $visitor200->id]);
    $v3 = Visit::factory()->create(['visitor_id' => $visitor300->id]);

    $response = $this->getJson('/api/reports/visits?visitor_no_from=1000020&visitor_no_to=1000080')
        ->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($v2->id);
    expect($ids)->not->toContain($v1->id, $v3->id);
});

it('filters visits by company case-insensitively on visit or visitor profile', function () {
    actingAsSupervisor();

    $visitorWithComp = Visitor::factory()->create(['company' => 'PT Sumber Makmur']);
    $visitorWithoutComp = Visitor::factory()->create(['company' => null]);

    // Matched via visits.visitor_company
    $v1 = Visit::factory()->create([
        'visitor_id' => $visitorWithoutComp->id,
        'visitor_company' => 'PT Sumber Makmur Sentosa',
    ]);
    // Matched via visitors.company
    $v2 = Visit::factory()->create([
        'visitor_id' => $visitorWithComp->id,
        'visitor_company' => null,
    ]);
    // Unrelated
    $v3 = Visit::factory()->create([
        'visitor_id' => $visitorWithoutComp->id,
        'visitor_company' => 'CV Lain',
    ]);

    $response = $this->getJson('/api/reports/visits?company=sumber+makmur')
        ->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($v1->id, $v2->id);
    expect($ids)->not->toContain($v3->id);
});

it('filters visits by visitor_name and occupation', function () {
    actingAsSupervisor();

    $vis1 = Visitor::factory()->create([
        'name' => 'Budi Santoso',
        'occupation' => 'Technician',
    ]);
    $vis2 = Visitor::factory()->create([
        'name' => 'Andi Wijaya',
        'occupation' => 'Supervisor',
    ]);

    $v1 = Visit::factory()->create(['visitor_id' => $vis1->id]);
    $v2 = Visit::factory()->create(['visitor_id' => $vis2->id]);

    $nameRes = $this->getJson('/api/reports/visits?visitor_name=santoso')
        ->assertOk();
    expect(collect($nameRes->json('data'))->pluck('id')->all())->toBe([$v1->id]);

    $occRes = $this->getJson('/api/reports/visits?occupation=superv')
        ->assertOk();
    expect(collect($occRes->json('data'))->pluck('id')->all())->toBe([$v2->id]);
});

it('filters visits by visitor_type', function () {
    actingAsSupervisor();

    $crew = Visitor::factory()->crew()->create();
    $guest = Visitor::factory()->create(['visitor_type' => VisitorType::Visitor->value]);

    $v1 = Visit::factory()->create(['visitor_id' => $crew->id]);
    $v2 = Visit::factory()->create(['visitor_id' => $guest->id]);

    $res = $this->getJson('/api/reports/visits?visitor_type=crew')
        ->assertOk();
    $ids = collect($res->json('data'))->pluck('id')->all();

    expect($ids)->toContain($v1->id);
    expect($ids)->not->toContain($v2->id);
});

it('automatically excludes crew visits from visitor report when visitor_type is not specified', function () {
    actingAsSupervisor();

    $crew = Visitor::factory()->crew()->create();
    $guest = Visitor::factory()->create(['visitor_type' => VisitorType::Visitor->value]);

    $v1 = Visit::factory()->create(['visitor_id' => $crew->id]);
    $v2 = Visit::factory()->create(['visitor_id' => $guest->id]);

    $res = $this->getJson('/api/reports/visits')
        ->assertOk();
    $ids = collect($res->json('data'))->pluck('id')->all();

    expect($ids)->toContain($v2->id);
    expect($ids)->not->toContain($v1->id);
});

it('filters visits by staff name or host_name', function () {
    actingAsSupervisor();

    $staff = Staff::factory()->create(['name' => 'Hendra Setiawan']);
    $v1 = Visit::factory()->create(['staff_id' => $staff->id, 'host_name' => null]);
    $v2 = Visit::factory()->create(['staff_id' => null, 'host_name' => 'Hendra Setiawan']);
    $v3 = Visit::factory()->create(['staff_id' => null, 'host_name' => 'Doni Siregar']);

    $res = $this->getJson('/api/reports/visits?staff=hendra')
        ->assertOk();
    $ids = collect($res->json('data'))->pluck('id')->all();

    expect($ids)->toContain($v1->id, $v2->id);
    expect($ids)->not->toContain($v3->id);
});

it('filters visits by location name or location_code_or_vehicle_no', function () {
    actingAsSupervisor();

    $locA = Location::factory()->create(['name' => 'DOCK UTAMA', 'code' => 'IMO-998877']);
    $locB = Location::factory()->create(['name' => 'WORKSHOP', 'code' => 'WS-01']);

    $v1 = Visit::factory()->create([
        'location_id' => $locA->id,
        'location_name' => 'DOCK UTAMA',
        'vehicle_no' => 'BP 1234 XX',
    ]);
    $v2 = Visit::factory()->create([
        'location_id' => $locB->id,
        'location_name' => 'WORKSHOP',
        'vehicle_no' => 'BP 9988 ZZ',
    ]);

    // Filter location name
    $resLoc = $this->getJson('/api/reports/visits?location=dock')
        ->assertOk();
    expect(collect($resLoc->json('data'))->pluck('id')->all())->toBe([$v1->id]);

    // Filter location_code_or_vehicle_no matching location code
    $resCode = $this->getJson('/api/reports/visits?location_code_or_vehicle_no=IMO-998877')
        ->assertOk();
    expect(collect($resCode->json('data'))->pluck('id')->all())->toBe([$v1->id]);

    // Filter location_code_or_vehicle_no matching vehicle plate
    $resPlate = $this->getJson('/api/reports/visits?location_code_or_vehicle_no=9988+ZZ')
        ->assertOk();
    expect(collect($resPlate->json('data'))->pluck('id')->all())->toBe([$v2->id]);

    // Filter vehicle_no directly
    $resVeh = $this->getJson('/api/reports/visits?vehicle_no=1234+XX')
        ->assertOk();
    expect(collect($resVeh->json('data'))->pluck('id')->all())->toBe([$v1->id]);
});

it('filters visits by card_no (visitor card or vehicle card) and card_type', function () {
    actingAsSupervisor();

    $vCard = VisitorCard::factory()->create([
        'card_no' => 'VC-7788',
        'card_type' => CardType::Crew->value,
    ]);
    $vehCard = VehicleCard::factory()->create(['card_no' => 'VH-5566']);

    $v1 = Visit::factory()->create(['vehicle_card_id' => null]);
    $v1->visitorCards()->attach($vCard->id);

    $v2 = Visit::factory()->create(['vehicle_card_id' => $vehCard->id]);

    // Filter card_no matching visitor card
    $res1 = $this->getJson('/api/reports/visits?card_no=VC-7788')->assertOk();
    expect(collect($res1->json('data'))->pluck('id')->all())->toContain($v1->id);
    expect(collect($res1->json('data'))->pluck('id')->all())->not->toContain($v2->id);

    // Filter card_no matching vehicle card
    $res2 = $this->getJson('/api/reports/visits?card_no=VH-5566')->assertOk();
    expect(collect($res2->json('data'))->pluck('id')->all())->toContain($v2->id);
    expect(collect($res2->json('data'))->pluck('id')->all())->not->toContain($v1->id);

    // Filter card_type
    $resType = $this->getJson('/api/reports/visits?card_type=crew')->assertOk();
    expect(collect($resType->json('data'))->pluck('id')->all())->toContain($v1->id);
    expect(collect($resType->json('data'))->pluck('id')->all())->not->toContain($v2->id);
});

it('filters visits by open_only / only_open flag', function () {
    actingAsSupervisor();

    $openVisit = Visit::factory()->create(['checked_out_at' => null]);
    $closedVisit = Visit::factory()->create(['checked_out_at' => now()]);

    $res = $this->getJson('/api/reports/visits?open_only=true')->assertOk();
    $ids = collect($res->json('data'))->pluck('id')->all();

    expect($ids)->toContain($openVisit->id);
    expect($ids)->not->toContain($closedVisit->id);

    // Also with only_open=1
    $res2 = $this->getJson('/api/reports/visits?only_open=1')->assertOk();
    $ids2 = collect($res2->json('data'))->pluck('id')->all();
    expect($ids2)->toContain($openVisit->id);
    expect($ids2)->not->toContain($closedVisit->id);
});

it('caps per_page at 100 and returns expected report keys', function () {
    actingAsSupervisor();

    $location = Location::factory()->create(['code' => 'DOCK-1', 'name' => 'Main Dock']);
    $visitor = Visitor::factory()->create(['name' => 'Jane Reporter', 'company' => 'PT Reporter']);
    $staff = Staff::factory()->create(['name' => 'Pak Staff']);
    $vCard = VisitorCard::factory()->create(['card_no' => 'VC-101']);

    $visit = Visit::factory()->create([
        'visitor_id' => $visitor->id,
        'location_id' => $location->id,
        'location_name' => 'Main Dock',
        'staff_id' => $staff->id,
        'host_name' => 'Pak Staff',
        'visitor_company' => 'PT Reporter',
        'purpose' => 'Inspection',
        'vehicle_no' => 'BP 1111 AA',
    ]);
    $visit->visitorCards()->attach($vCard->id);

    $res = $this->getJson('/api/reports/visits?per_page=150')->assertOk();

    // Verify per_page cap at 100
    expect($res->json('per_page'))->toBe(100);

    // Verify structure and keys
    $row = collect($res->json('data'))->firstWhere('id', $visit->id);
    expect($row)->not->toBeNull();
    expect($row)->toHaveKeys([
        'id', 'date', 'time_in', 'time_out', 'checked_out_at', 'days_open',
        'ticket_number', 'legacy_registration_no', 'visitor_name', 'visitor_company',
        'visitor_cards', 'vehicle_card', 'host_name', 'location_name', 'location_code',
        'purpose', 'vehicle_no',
    ]);
    expect($row['visitor_name'])->toBe('Jane Reporter');
    expect($row['location_code'])->toBe('DOCK-1');
    expect($row['visitor_company'])->toBe('PT Reporter');
});
