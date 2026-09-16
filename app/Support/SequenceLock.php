<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Serialises number allocation (visitor_no, ticket_number) across concurrent
 * requests. On PostgreSQL a transaction-scoped advisory lock is taken before the
 * "highest number so far" query, so a waiting request reads the maximum only
 * after the holder has committed. A row lock cannot do that: under READ
 * COMMITTED the waiter resumes with the maximum it read before the holder
 * committed, and an empty range has no row to lock. SQLite, used by the test
 * suite, already serialises writers with its database lock.
 */
final class SequenceLock
{
    public static function acquire(string $sequence): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                "SequenceLock::acquire('{$sequence}') must run inside a database transaction: the lock is released when that transaction ends."
            );
        }

        DB::select('SELECT pg_advisory_xact_lock(CAST(? AS bigint))', [self::key($sequence)]);
    }

    /** Stable key per sequence name, identical in every PHP process. */
    public static function key(string $sequence): int
    {
        return crc32('vms-sequence:'.$sequence);
    }
}
