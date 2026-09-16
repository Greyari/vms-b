<?php

use App\Models\Staff;
use App\Models\Visit;
use App\Models\Visitor;
use App\Support\SequenceLock;
use App\Support\TicketNumber;
use App\Support\UniqueViolation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

it('starts the ticket counter at 00001 for the month', function () {
    expect(TicketNumber::next(Carbon::parse('2026-09-15 08:00:00')))->toBe('26/09/00001');
});

it('continues the ticket counter within the month', function () {
    Visit::factory()->create(['ticket_number' => '26/09/00012']);

    expect(TicketNumber::next(Carbon::parse('2026-09-30 23:00:00')))->toBe('26/09/00013');
});

it('restarts the ticket counter in a new month however high the last month went', function () {
    Visit::factory()->create(['ticket_number' => '26/09/99999']);

    expect(TicketNumber::next(Carbon::parse('2026-10-01 07:00:00')))->toBe('26/10/00001');
});

it('restarts the ticket counter in a new year', function () {
    Visit::factory()->create(['ticket_number' => '26/12/00450']);

    expect(TicketNumber::next(Carbon::parse('2027-01-01 00:05:00')))->toBe('27/01/00001');
});

it('recognises a real ticket-number collision as retryable', function () {
    Visit::factory()->create(['ticket_number' => '26/09/00001']);

    try {
        DB::transaction(fn () => Visit::factory()->create(['ticket_number' => '26/09/00001']));
        $this->fail('Expected a unique violation.');
    } catch (UniqueConstraintViolationException $e) {
        expect(UniqueViolation::isRetryable($e))->toBeTrue();
    }
});

it('does not treat another real unique violation as retryable', function () {
    Staff::factory()->create(['staff_id' => 'STF-000001']);

    try {
        DB::transaction(fn () => Staff::factory()->create(['staff_id' => 'STF-000001']));
        $this->fail('Expected a unique violation.');
    } catch (UniqueConstraintViolationException $e) {
        expect(UniqueViolation::isRetryable($e))->toBeFalse();
    }
});

/**
 * Whether another database session is kept from taking the sequence lock.
 * Opens a second PostgreSQL connection, because advisory locks are per session.
 */
function sequenceLockIsHeldElsewhere(string $sequence): bool
{
    config(['database.connections.pgsql_probe' => config('database.connections.pgsql')]);

    try {
        $probe = DB::connection('pgsql_probe');
        $got = (int) $probe->selectOne(
            'SELECT CASE WHEN pg_try_advisory_lock(CAST(? AS bigint)) THEN 1 ELSE 0 END AS got',
            [SequenceLock::key($sequence)]
        )->got === 1;

        if ($got) {
            $probe->select('SELECT pg_advisory_unlock(CAST(? AS bigint))', [SequenceLock::key($sequence)]);
        }

        return ! $got;
    } finally {
        DB::purge('pgsql_probe');
    }
}

it('holds the visitor-number lock until the allocating transaction ends', function () {
    requirePostgres();

    expect(sequenceLockIsHeldElsewhere('visitor_no'))->toBeFalse();

    Visitor::nextVisitorNo();

    expect(sequenceLockIsHeldElsewhere('visitor_no'))->toBeTrue();
})->group('pgsql');

it('holds the ticket-number lock until the allocating transaction ends', function () {
    requirePostgres();

    expect(sequenceLockIsHeldElsewhere('ticket_number'))->toBeFalse();

    TicketNumber::next();

    expect(sequenceLockIsHeldElsewhere('ticket_number'))->toBeTrue();
})->group('pgsql');
