<?php

namespace App\Support;

use App\Models\Visit;
use Carbon\CarbonInterface;

/**
 * Ticket numbers read YY/MM/NNNNN, and the counter restarts every month (owner
 * decision 2026-09-15). A yearly counter would pass 99,999 at the gate's volume
 * of about 111,000 visits a year, and five digits stop sorting in issue order
 * after that. Tickets issued earlier under a yearly counter keep their numbers:
 * the month prefix keeps the two apart, and the current month simply continues
 * from its highest ticket.
 */
final class TicketNumber
{
    public static function next(?CarbonInterface $now = null): string
    {
        SequenceLock::acquire('ticket_number');

        // Read the clock after any wait for the lock, so a request that waited
        // across a month end is numbered in the month it is recorded.
        $now ??= now();

        $prefix = $now->format('y/m/');
        $start = $prefix.'00000';
        $nextMonth = $now->copy()->startOfMonth()->addMonth();
        $end = $nextMonth->format('y/m/').'00000';

        // Range comparison (>= $start and < $end) allows PostgreSQL to utilize
        // a backward B-tree index scan on visits_ticket_number_unique across all
        // collations without falling back to sequential scan on high volumes.
        $latest = Visit::query()
            ->whereNotNull('ticket_number')
            ->where('ticket_number', '>=', $start)
            ->where('ticket_number', '<', $end)
            ->orderByDesc('ticket_number')
            ->value('ticket_number');

        $counter = $latest === null ? 1 : ((int) substr((string) strrchr($latest, '/'), 1)) + 1;

        return $prefix.str_pad((string) $counter, 5, '0', STR_PAD_LEFT);
    }
}
