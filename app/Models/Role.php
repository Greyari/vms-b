<?php

namespace App\Models;

use App\Traits\HasUuidV7;
use App\Traits\LogsActivity;
use Spatie\Permission\Models\Role as SpatieRole;

// Extend Spatie's model (don't edit vendor/ directly) so we can add UUID v7
class Role extends SpatieRole
{
    use HasUuidV7, LogsActivity;
}
