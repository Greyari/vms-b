<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    requirePostgres();
    DB::statement('SET enable_seqscan = off');
});

it('uses btree index scan for monthly ticket range queries on visits_ticket_number_unique', function () {
    $explain = DB::select("EXPLAIN SELECT ticket_number FROM visits WHERE ticket_number IS NOT NULL AND ticket_number >= '26/09/00000' AND ticket_number < '26/10/00000' ORDER BY ticket_number DESC LIMIT 1");
    $planText = collect($explain)->pluck('QUERY PLAN')->join("\n");

    expect($planText)->toMatch('/Index (Only )?Scan/');
    expect($planText)->toContain('visits_ticket_number_unique');
    expect($planText)->not->toContain('Seq Scan');
})->group('pgsql');

it('uses index scan for open visits queries on visits_checked_out_at_index', function () {
    $explain = DB::select('EXPLAIN SELECT * FROM visits WHERE checked_out_at IS NULL');
    $planText = collect($explain)->pluck('QUERY PLAN')->join("\n");

    expect($planText)->toMatch('/Index (Only )?Scan/');
    expect($planText)->toContain('visits_checked_out_at_index');
    expect($planText)->not->toContain('Seq Scan');
})->group('pgsql');

it('uses composite unique index scan for normalized visitor identity lookups on visitors_id_type_id_number_unique', function () {
    $explain = DB::select("EXPLAIN SELECT * FROM visitors WHERE id_type = 'KTP' AND id_number = '1234567890123456'");
    $planText = collect($explain)->pluck('QUERY PLAN')->join("\n");

    expect($planText)->toMatch('/Index (Only )?Scan/');
    expect($planText)->toContain('visitors_id_type_id_number_unique');
    expect($planText)->not->toContain('Seq Scan');
})->group('pgsql');

it('uses composite index scan for vessel arrivals filtering by location and date range on vessel_arrivals_location_id_sign_in_date_index', function () {
    $explain = DB::select("EXPLAIN SELECT * FROM vessel_arrivals WHERE location_id = '00000000-0000-0000-0000-000000000000' AND sign_in_date >= '2026-09-01' AND sign_in_date <= '2026-09-30'");
    $planText = collect($explain)->pluck('QUERY PLAN')->join("\n");

    expect($planText)->toMatch('/Index (Only )?Scan/');
    expect($planText)->toContain('vessel_arrivals_location_id_sign_in_date_index');
    expect($planText)->not->toContain('Seq Scan');
})->group('pgsql');

it('uses index scan for date range filtering on visits_date_index', function () {
    $explain = DB::select("EXPLAIN SELECT * FROM visits WHERE date >= '2026-09-01' AND date <= '2026-09-30'");
    $planText = collect($explain)->pluck('QUERY PLAN')->join("\n");

    expect($planText)->toMatch('/Index (Only )?Scan/');
    expect($planText)->toContain('visits_date_index');
    expect($planText)->not->toContain('Seq Scan');
})->group('pgsql');
