<?php

namespace App\Models;

use App\Traits\HasUuidV7;
use App\Traits\LogsActivity;
use Spatie\Permission\Models\Role as SpatieRole;

// Extend Spatie's model (don't edit vendor/ directly) so we can add UUID v7
class Role extends SpatieRole
{
    use HasUuidV7, LogsActivity;

    /**
     * RoleAndPermissionSeeder syncs these roles' permissions on every run, so an edit made in the UI
     * would silently vanish at the next seed. The API refuses to change or delete them and the SPA
     * shows them read-only (decision FE-3-ROLE, 2026-09-28).
     */
    public const BUILT_IN = ['administrator', 'supervisor', 'security', 'operator_pos'];

    /**
     * Every role and permission lives on the web guard, where the seeder puts them. Without this a
     * role created during an API request took the request's `sanctum` guard and could not be given
     * any permission: POST /api/roles with permissions answered 500.
     */
    protected $guard_name = 'web';

    protected $appends = ['is_builtin'];

    public function getIsBuiltinAttribute(): bool
    {
        return in_array($this->name, self::BUILT_IN, true);
    }
}
