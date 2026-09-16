<?php

namespace App\Support;

use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Tells apart the unique violations a retry can resolve: two requests that
 * computed the same ticket number or visitor_no. Two requests registering the
 * same new person need no retry, because firstOrCreate and updateOrCreate
 * already read the committed row back inside the first attempt. Any other
 * unique violation fails the same way again, so retrying it only hides the
 * real error.
 */
final class UniqueViolation
{
    /** @var array<int, array{0: string, 1: array<int, string>}> */
    private const RETRYABLE = [
        ['visits', ['ticket_number']],
        ['visitors', ['visitor_no']],
    ];

    public static function isRetryable(UniqueConstraintViolationException $e): bool
    {
        foreach (self::RETRYABLE as [$table, $columns]) {
            if (self::violates($e, $table, $columns)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Matches PostgreSQL, which names the index Laravel created
     * ("visitors_visitor_no_unique"), and SQLite, which lists the columns
     * ("UNIQUE constraint failed: visitors.visitor_no").
     *
     * @param  array<int, string>  $columns
     */
    public static function violates(UniqueConstraintViolationException $e, string $table, array $columns): bool
    {
        $message = $e->getMessage();
        $indexName = $table.'_'.implode('_', $columns).'_unique';
        $sqliteColumns = implode(', ', array_map(fn (string $column): string => $table.'.'.$column, $columns));

        return str_contains($message, '"'.$indexName.'"')
            || str_contains($message, 'UNIQUE constraint failed: '.$sqliteColumns);
    }
}
