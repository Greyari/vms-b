<?php

use App\Models\Location;
use App\Models\VesselArrival;
use App\Models\VesselArrivalCrew;
use App\Models\Visitor;

it('forbids unauthenticated user from accessing crew report and export', function () {
    $this->getJson('/api/reports/crew')->assertUnauthorized();
    $this->getJson('/api/reports/crew/export')->assertUnauthorized();
});

it('forbids security role from accessing crew report and export', function () {
    actingAsSecurity();

    $this->getJson('/api/reports/crew')->assertForbidden();
    $this->getJson('/api/reports/crew/export')->assertForbidden();
});

it('lists crew placements with vessel details, status, and supports filters', function () {
    actingAsSupervisor();

    $vessel = Location::factory()->create(['name' => 'MV. PACIFIC EXPLORER', 'code' => 'IMO-991122']);
    $arrival = VesselArrival::factory()->create([
        'location_id' => $vessel->id,
        'vessel_name' => 'MV. PACIFIC EXPLORER',
        'sign_in_date' => '2026-10-01',
    ]);

    $crew1 = Visitor::factory()->crew($vessel)->create([
        'name' => 'Budi Santoso',
        'id_number' => '1234567890123456',
        'nationality' => 'Indonesia',
        'occupation' => 'Chief Engineer',
        'seaman_book_no' => 'SB-100200',
    ]);

    $crew2 = Visitor::factory()->crew($vessel)->create([
        'name' => 'Michael Chen',
        'id_number' => 'S9876543A',
        'nationality' => 'Singapore',
        'occupation' => 'Second Officer',
        'seaman_book_no' => 'SB-555666',
    ]);

    VesselArrivalCrew::create([
        'vessel_arrival_id' => $arrival->id,
        'visitor_id' => $crew1->id,
        'status' => 'checked_in',
        'checked_in_at' => now(),
    ]);

    VesselArrivalCrew::create([
        'vessel_arrival_id' => $arrival->id,
        'visitor_id' => $crew2->id,
        'status' => 'checked_out',
        'checked_in_at' => now()->subDays(2),
        'checked_out_at' => now()->subDay(),
    ]);

    // 1. Fetch all crew placements
    $res = $this->getJson('/api/reports/crew')->assertOk();
    $data = $res->json('data');
    expect($data)->not->toBeEmpty();
    expect(count($data))->toBeGreaterThanOrEqual(2);

    // 2. Filter by search keyword
    $resSearch = $this->getJson('/api/reports/crew?search=Budi')->assertOk();
    $searchData = $resSearch->json('data');
    expect(count($searchData))->toBe(1);
    expect($searchData[0]['visitor']['name'])->toBe('Budi Santoso');
    expect($searchData[0]['vessel_arrival']['vessel_name'])->toBe('MV. PACIFIC EXPLORER');

    // 3. Filter by status
    $resCheckedIn = $this->getJson('/api/reports/crew?status=checked_in')->assertOk();
    $checkedInData = $resCheckedIn->json('data');
    $budi = collect($checkedInData)->firstWhere('visitor_id', $crew1->id);
    expect($budi)->not->toBeNull();
    expect($budi['status'])->toBe('checked_in');

    // 4. Filter by nationality
    $resNat = $this->getJson('/api/reports/crew?nationality=Singapore')->assertOk();
    $natData = $resNat->json('data');
    expect(count($natData))->toBe(1);
    expect($natData[0]['visitor']['name'])->toBe('Michael Chen');
});

it('exports crew placements to excel stream download', function () {
    actingAsSupervisor();

    $vessel = Location::factory()->create(['name' => 'TB. MARINEX 01']);
    $arrival = VesselArrival::factory()->create([
        'location_id' => $vessel->id,
        'vessel_name' => 'TB. MARINEX 01',
    ]);

    $crew = Visitor::factory()->crew($vessel)->create([
        'name' => 'Ahmad Pelaut',
    ]);

    VesselArrivalCrew::create([
        'vessel_arrival_id' => $arrival->id,
        'visitor_id' => $crew->id,
        'status' => 'checked_in',
        'checked_in_at' => now(),
    ]);

    $response = $this->get('/api/reports/crew/export');
    $response->assertOk();
    $response->assertHeader('content-disposition', 'attachment; filename=vessel-crew-report.xlsx');
});
