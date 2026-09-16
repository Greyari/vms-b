<?php

namespace App\Support;

/**
 * One spelling per username. VMS-old signed people in against SQL Server's
 * case-insensitive collation, so an Employee ID typed in lower case still
 * worked there; PostgreSQL compares case-sensitively. Usernames are therefore
 * stored and looked up upper-cased, the way VMS-old account names were written
 * (BBM-SEKWAN). A blank value means "no username".
 */
final class Username
{
    public static function normalize(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : mb_strtoupper($trimmed);
    }
}
