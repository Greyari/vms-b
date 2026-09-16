<?php

use Illuminate\Support\Facades\DB;

it('runs the integration group against a real PostgreSQL connection', function () {
    requirePostgres();

    expect(DB::connection()->getDriverName())->toBe('pgsql');
    expect((int) DB::selectOne('select 1 as ok')->ok)->toBe(1);
})->group('pgsql');
