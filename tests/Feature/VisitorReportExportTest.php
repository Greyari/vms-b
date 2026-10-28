<?php

use App\Models\Location;
use App\Models\Staff;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('forbids unauthenticated user from exporting visitor reports', function () {
    $this->getJson('/api/reports/visits/export')->assertUnauthorized();
    $this->getJson('/api/reports/visits/pdf')->assertUnauthorized();
    $this->getJson('/api/visits/export')->assertUnauthorized();
    $this->getJson('/api/visits/export-pdf')->assertUnauthorized();
});

it('forbids security role from exporting visitor reports', function () {
    actingAsSecurity();

    $this->getJson('/api/reports/visits/export')->assertForbidden();
    $this->getJson('/api/reports/visits/pdf')->assertForbidden();
    $this->getJson('/api/visits/export')->assertForbidden();
    $this->getJson('/api/visits/export-pdf')->assertForbidden();
});

it('exports filtered visits to excel stream download', function () {
    actingAsSupervisor();

    $loc = Location::factory()->create(['name' => 'JETTY 1', 'code' => 'J-01']);
    $visitor = Visitor::factory()->create(['name' => 'Doni Seafarer', 'company' => 'PT Pelayaran']);
    $staff = Staff::factory()->create(['name' => 'Budi Staff']);
    $vCard = VisitorCard::factory()->create(['card_no' => 'VC-88001']);

    $visit = Visit::factory()->create([
        'visitor_id' => $visitor->id,
        'location_id' => $loc->id,
        'location_name' => 'JETTY 1',
        'staff_id' => $staff->id,
        'host_name' => 'Budi Staff',
        'visitor_company' => 'PT Pelayaran',
        'date' => '2026-09-20',
        'time_in' => '08:00:00',
        'ticket_number' => '26/09/00001',
    ]);
    $visit->visitorCards()->attach($vCard->id);

    // Call new route
    $response = $this->get('/api/reports/visits/export?company=pelayaran');
    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('attachment');
    expect($response->headers->get('content-disposition'))->toContain('visitor-report.xlsx');

    // Call legacy alias
    $legacyResponse = $this->get('/api/visits/export?company=pelayaran');
    $legacyResponse->assertOk();
    expect($legacyResponse->headers->get('content-disposition'))->toContain('attachment');
});

it('exports visits to landscape a4 pdf download', function () {
    actingAsSupervisor();

    $loc = Location::factory()->create(['name' => 'DOCK 2']);
    $visitor = Visitor::factory()->create(['name' => 'Ahmad Visitor']);

    $visit = Visit::factory()->create([
        'visitor_id' => $visitor->id,
        'location_id' => $loc->id,
        'location_name' => 'DOCK 2',
        'date' => '2026-09-21',
        'time_in' => '09:00:00',
    ]);

    // Call new route
    $response = $this->get('/api/reports/visits/pdf?date_from=2026-09-20&date_to=2026-09-22');
    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('application/pdf');

    // Call legacy alias
    $legacyResponse = $this->get('/api/visits/export-pdf?date_from=2026-09-20&date_to=2026-09-22');
    $legacyResponse->assertOk();
    expect($legacyResponse->headers->get('content-type'))->toBe('application/pdf');
});

it('returns unpaginated collection when print=true under limit', function () {
    actingAsSupervisor();

    Visit::factory()->count(3)->create(['date' => '2026-09-20']);

    $res = $this->getJson('/api/reports/visits?date_from=2026-09-20&date_to=2026-09-20&print=true')
        ->assertOk();

    // In print mode, returns plain array of visits up to limit
    $data = $res->json();
    expect(is_array($data))->toBeTrue();
    expect(count($data))->toBe(3);
    expect($data[0])->toHaveKeys(['id', 'date', 'ticket_number', 'visitor_name', 'location_name']);
});

it('allows printing beyond 5000 rows without 422 limit', function () {
    actingAsSupervisor();
    $visitor = Visitor::factory()->create();

    // Fast bulk insert of 5,005 rows
    $now = now();
    $rows = [];
    for ($i = 0; $i < 5005; $i++) {
        $rows[] = [
            'id' => (string) Str::uuid(),
            'visitor_id' => $visitor->id,
            'purpose' => 'Inspection',
            'date' => '2026-09-15',
            'time_in' => '08:00:00',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    foreach (array_chunk($rows, 1000) as $chunk) {
        DB::table('visits')->insert($chunk);
    }

    $res = $this->getJson('/api/reports/visits?date_from=2026-09-15&date_to=2026-09-15&print=true');
    $res->assertOk();
    expect(count($res->json()))->toBe(5005);
});
