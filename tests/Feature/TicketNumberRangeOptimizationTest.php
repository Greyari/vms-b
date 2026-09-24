<?php

use App\Models\Visit;
use App\Support\TicketNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

it('generates sequential ticket numbers using range predicates within the month', function () {
    expect(TicketNumber::next(Carbon::parse('2026-09-01 08:00:00')))->toBe('26/09/00001');

    Visit::factory()->create(['ticket_number' => '26/09/00042']);

    expect(TicketNumber::next(Carbon::parse('2026-09-15 12:00:00')))->toBe('26/09/00043');
});

it('resets ticket counter in a new month using range bounds', function () {
    Visit::factory()->create(['ticket_number' => '26/09/00500']);

    expect(TicketNumber::next(Carbon::parse('2026-10-01 00:01:00')))->toBe('26/10/00001');
});

it('resets ticket counter across year boundary from December to January', function () {
    Visit::factory()->create(['ticket_number' => '26/12/01200']);

    expect(TicketNumber::next(Carbon::parse('2027-01-01 06:00:00')))->toBe('27/01/00001');
});

it('uses btree index scan on PostgreSQL for range queries on ticket_number', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL EXPLAIN verification requires pgsql connection.');
    }

    $explain = DB::select("EXPLAIN SELECT ticket_number FROM visits WHERE ticket_number IS NOT NULL AND ticket_number >= '26/09/00000' AND ticket_number < '26/10/00000' ORDER BY ticket_number DESC LIMIT 1");
    $planText = collect($explain)->pluck('QUERY PLAN')->join("\n");

    expect($planText)->toMatch('/Index (Only )?Scan/');
    expect($planText)->not->toContain('Seq Scan');
})->group('pgsql');
