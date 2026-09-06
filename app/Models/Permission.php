<?php

namespace App\Models;

use App\Traits\HasUuidV7;
use Spatie\Permission\Models\Permission as SpatiePermission;

// Extend Spatie's model (don't edit vendor/ directly) so we can add UUID v7
class Permission extends SpatiePermission
{
    use HasUuidV7;
}
