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
        $now ??= now();

        SequenceLock::acquire('ticket_number');

        $prefix = $now->format('y/m/');

        // Within one month the fixed-width counter sorts in issue order as a
        // string, so the highest ticket carries the highest counter. IS NOT NULL
        // lets PostgreSQL walk the unique index backwards from the newest ticket
        // instead of wading through imported visits, which have none.
        $latest = Visit::query()
            ->whereNotNull('ticket_number')
            ->where('ticket_number', 'like', $prefix.'%')
            ->orderByDesc('ticket_number')
            ->value('ticket_number');

        $counter = $latest === null ? 1 : ((int) substr((string) strrchr($latest, '/'), 1)) + 1;

        return $prefix.str_pad((string) $counter, 5, '0', STR_PAD_LEFT);
    }
}
