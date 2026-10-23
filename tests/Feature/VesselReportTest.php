<?php

use App\Models\Location;
use App\Models\VesselArrival;
use App\Models\Visitor;

it('forbids unauthenticated user from accessing vessel report and export', function () {
    $this->getJson('/api/reports/vessels')->assertUnauthorized();
    $this->getJson('/api/reports/vessels/export')->assertUnauthorized();
    $this->getJson('/api/reports/vessels/pdf')->assertUnauthorized();
});

it('forbids security role from accessing vessel report and export', function () {
    actingAsSecurity();

    $this->getJson('/api/reports/vessels')->assertForbidden();
    $this->getJson('/api/reports/vessels/export')->assertForbidden();
    $this->getJson('/api/reports/vessels/pdf')->assertForbidden();
});

it('lists vessels in list_ships mode with total_days calculation', function () {
    actingAsSupervisor();

    $vessel = Location::factory()->create(['name' => 'MV. OCEAN STAR', 'code' => 'IMO-889900']);

    $arrival = VesselArrival::factory()->create([
        'location_id' => $vessel->id,
        'vessel_name' => 'MV. OCEAN STAR',
        'sign_in_date' => '2026-09-10',
        'sign_out_date' => '2026-09-15',
        'captain' => 'Capt. Ahmad',
    ]);

    $res = $this->getJson('/api/reports/vessels?mode=list_ships')->assertOk();

    $data = $res->json('data');
    expect($data)->not->toBeEmpty();
    $row = collect($data)->firstWhere('id', $arrival->id);
    expect($row)->not->toBeNull();
    expect($row['vessel_name'])->toBe('MV. OCEAN STAR');
    expect($row['captain'])->toBe('Capt. Ahmad');
    expect($row['total_days'])->toBe(5);
    expect($row['vessel']['code'])->toBe('IMO-889900');
});

it('lists vessels in list_ships_with_crew mode with crew details', function () {
    actingAsSupervisor();

    $vessel = Location::factory()->create(['name' => 'MV. BLUE HORIZON']);
    $arrival = VesselArrival::factory()->create([
        'location_id' => $vessel->id,
        'vessel_name' => 'MV. BLUE HORIZON',
    ]);

    $crew1 = Visitor::factory()->crew($vessel)->create([
        'name' => 'John Sailor',
        'occupation' => 'Chief Cook',
    ]);
    $crew2 = Visitor::factory()->crew($vessel)->create([
        'name' => 'Peter Deck',
        'occupation' => 'Oiler',
    ]);
    $arrival->crew()->attach([$crew1->id, $crew2->id]);

    $res = $this->getJson('/api/reports/vessels?mode=list_ships_with_crew')->assertOk();

    $row = collect($res->json('data'))->firstWhere('id', $arrival->id);
    expect($row)->not->toBeNull();
    expect($row['crew'])->toHaveCount(2);
    expect(collect($row['crew'])->pluck('name')->all())->toContain('John Sailor', 'Peter Deck');
    expect(collect($row['crew'])->pluck('occupation')->all())->toContain('Chief Cook', 'Oiler');
});

it('filters vessel report by stay_status in, out, not_out', function () {
    actingAsSupervisor();

    $arrivalIn = VesselArrival::factory()->create([
        'sign_in_date' => '2026-09-10',
        'sign_out_date' => null,
    ]);
    $arrivalOut = VesselArrival::factory()->create([
        'sign_in_date' => '2026-09-05',
        'sign_out_date' => '2026-09-12',
    ]);

    // stay_status = not_out (only open arrivals)
    $resNotOut = $this->getJson('/api/reports/vessels?stay_status=not_out')->assertOk();
    $notOutIds = collect($resNotOut->json('data'))->pluck('id')->all();
    expect($notOutIds)->toContain($arrivalIn->id);
    expect($notOutIds)->not->toContain($arrivalOut->id);

    // stay_status = out (only signed out arrivals)
    $resOut = $this->getJson('/api/reports/vessels?stay_status=out')->assertOk();
    $outIds = collect($resOut->json('data'))->pluck('id')->all();
    expect($outIds)->toContain($arrivalOut->id);
    expect($outIds)->not->toContain($arrivalIn->id);

    // stay_status = out with date range applied to sign_out_date
    $resOutDate = $this->getJson('/api/reports/vessels?stay_status=out&date_from=2026-09-11&date_to=2026-09-13')->assertOk();
    expect(collect($resOutDate->json('data'))->pluck('id')->all())->toContain($arrivalOut->id);

    $resOutDateMismatch = $this->getJson('/api/reports/vessels?stay_status=out&date_from=2026-09-20&date_to=2026-09-25')->assertOk();
    expect(collect($resOutDateMismatch->json('data'))->pluck('id')->all())->not->toContain($arrivalOut->id);
});

it('filters vessel report by vessel_name case-insensitively', function () {
    actingAsSupervisor();

    $arr1 = VesselArrival::factory()->create(['vessel_name' => 'BARGE GLORY 101']);
    $arr2 = VesselArrival::factory()->create(['vessel_name' => 'TUG BOAT PERKASA']);

    $res = $this->getJson('/api/reports/vessels?vessel_name=glory')->assertOk();
    $ids = collect($res->json('data'))->pluck('id')->all();

    expect($ids)->toContain($arr1->id);
    expect($ids)->not->toContain($arr2->id);
});

it('filters vessel report by crew_visitor_no range in list_ships_with_crew mode', function () {
    actingAsSupervisor();

    $vessel1 = Location::factory()->create();
    $vessel2 = Location::factory()->create();

    $arr1 = VesselArrival::factory()->create(['location_id' => $vessel1->id]);
    $arr2 = VesselArrival::factory()->create(['location_id' => $vessel2->id]);

    $crewLow = Visitor::factory()->crew($vessel1)->create(['visitor_no' => 1000010]);
    $crewHigh = Visitor::factory()->crew($vessel2)->create(['visitor_no' => 1000999]);

    $arr1->crew()->attach($crewLow->id);
    $arr2->crew()->attach($crewHigh->id);

    $res = $this->getJson('/api/reports/vessels?mode=list_ships_with_crew&crew_visitor_no_from=1000000&crew_visitor_no_to=1000050')->assertOk();
    $ids = collect($res->json('data'))->pluck('id')->all();

    expect($ids)->toContain($arr1->id);
    expect($ids)->not->toContain($arr2->id);
});

it('exports vessels report to excel stream download in list_ships mode', function () {
    actingAsSupervisor();

    $vessel = Location::factory()->create(['name' => 'MV. EXPORT TEST']);
    VesselArrival::factory()->create([
        'location_id' => $vessel->id,
        'vessel_name' => 'MV. EXPORT TEST',
    ]);

    $res = $this->get('/api/reports/vessels/export?mode=list_ships');
    $res->assertOk();
    expect($res->headers->get('content-disposition'))->toContain('attachment');
    expect($res->headers->get('content-disposition'))->toContain('vessel-report.xlsx');
});

it('exports vessels report to excel stream download in list_ships_with_crew mode', function () {
    actingAsSupervisor();

    $vessel = Location::factory()->create(['name' => 'MV. EXPORT WITH CREW']);
    $arr = VesselArrival::factory()->create([
        'location_id' => $vessel->id,
        'vessel_name' => 'MV. EXPORT WITH CREW',
    ]);
    $crew = Visitor::factory()->crew($vessel)->create(['name' => 'Export Crew Sailor']);
    $arr->crew()->attach($crew->id);

    $res = $this->get('/api/reports/vessels/export?mode=list_ships_with_crew');
    $res->assertOk();
    expect($res->headers->get('content-disposition'))->toContain('attachment');
    expect($res->headers->get('content-disposition'))->toContain('vessel-report.xlsx');
});

it('exports vessels report to landscape a4 pdf download in list_ships mode', function () {
    actingAsSupervisor();

    $vessel = Location::factory()->create(['name' => 'MV. PDF SHIP']);
    VesselArrival::factory()->create([
        'location_id' => $vessel->id,
        'vessel_name' => 'MV. PDF SHIP',
        'sign_in_date' => '2026-10-10',
    ]);

    $res = $this->get('/api/reports/vessels/pdf?mode=list_ships&date_from=2026-10-01&date_to=2026-10-15');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toBe('application/pdf');
    expect($res->headers->get('content-disposition'))->toContain('vessel-report.pdf');
});

it('exports vessels report to landscape a4 pdf download in list_ships_with_crew mode', function () {
    actingAsSupervisor();

    $vessel = Location::factory()->create(['name' => 'MV. PDF SHIP CREW']);
    $arr = VesselArrival::factory()->create([
        'location_id' => $vessel->id,
        'vessel_name' => 'MV. PDF SHIP CREW',
    ]);
    $crew = Visitor::factory()->crew($vessel)->create(['name' => 'PDF Crew Member']);
    $arr->crew()->attach($crew->id);

    $res = $this->get('/api/reports/vessels/pdf?mode=list_ships_with_crew');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toBe('application/pdf');
    expect($res->headers->get('content-disposition'))->toContain('vessel-crew-manifest-report.pdf');
});
