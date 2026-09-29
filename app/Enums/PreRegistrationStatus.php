<?php

namespace App\Enums;

/**
 * Where a pulled pre-registration stands on this gate. The hub never knows: it deletes
 * its copy once the yard acknowledges the pull (decision PE-4).
 */
enum PreRegistrationStatus: string
{
    case Pending = 'pending';
    case CheckedIn = 'checked_in';
    case Expired = 'expired';
}
