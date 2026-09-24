<?php

namespace App\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Runs a write that allocates visitor_no or ticket_number, each attempt in its
 * own transaction, and runs it again — up to MAX_ATTEMPTS in all — when the
 * attempt lost a numbering race (UniqueViolation::isRetryable()). Any other
 * unique violation reaches the caller at once; a violation that is still
 * retryable when it reaches the caller means every attempt lost the race. The
 * rolled-back transaction has undone everything the attempt wrote, so the
 * attempt must rebuild its own state each time.
 */
final class NumberingRetry
{
    public const MAX_ATTEMPTS = 3;

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $attempt
     * @return TResult
     *
     * @throws UniqueConstraintViolationException
     */
    public static function run(callable $attempt): mixed
    {
        for ($try = 1; ; $try++) {
            try {
                return DB::transaction($attempt);
            } catch (UniqueConstraintViolationException $e) {
                if ($try >= self::MAX_ATTEMPTS || ! UniqueViolation::isRetryable($e)) {
                    throw $e;
                }
            }
        }
    }
}
