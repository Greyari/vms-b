<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Support\Facades\Hash;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            'view_visits', 'create_visits', 'export_visits', 'checkout_visits', 'delete_visits',
            'view_visitors', 'manage_visitors', 'manage_blacklist',
            'view_staffs', 'manage_staffs',
            'view_cards', 'manage_cards',
            'view_users', 'manage_users',
            'view_roles', 'manage_roles',
            'view_logs',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $adminRole = Role::firstOrCreate(['name' => 'administrator']);
        // Admin gets EVERYTHING (incl. the newly added permissions) — this prevents lockout.
        $adminRole->syncPermissions(Permission::all());

        $securityRole = Role::firstOrCreate(['name' => 'security']);
        $securityRole->syncPermissions([
            'view_visits', 'create_visits', 'checkout_visits',
            'view_staffs', 'view_cards', 'view_visitors',
        ]);

        $admin = User::firstOrCreate(
            ['email' => 'admin@vms.local'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password123')
            ]
        );
        $admin->syncRoles([$adminRole->name]);

        $security = User::firstOrCreate(
            ['email' => 'security@vms.local'],
            [
                'name' => 'Security Guard',
                'password' => Hash::make('password123')
            ]
        );
        $security->syncRoles([$securityRole->name]);
    }
}
