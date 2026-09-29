<?php

namespace App\Enums;

/**
 * One row per sync channel in sync_state_logs; the header indicator reads them.
 */
enum SyncKey: string
{
    case PreRegistrations = 'pre_registrations';
    case BlacklistPull = 'blacklist_pull';
    case BlacklistPush = 'blacklist_push';
}
