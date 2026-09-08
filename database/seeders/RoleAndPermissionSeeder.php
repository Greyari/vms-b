<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
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

        // Akun awal HANYA dibuat kalau password-nya dipasok dari environment.
        //
        // Sebelumnya keduanya lahir dengan password 'password123' yang tertulis
        // di dalam repo. Di laptop itu sekadar praktis; di Azure endpoint
        // login-nya bisa dijangkau siapa saja, jadi seeder yang jalan dari
        // entrypoint container cukup untuk menyerahkan akun administrator
        // kepada siapa pun yang pernah membaca berkas ini.
        //
        // Tanpa env, seeder tetap menanam permission dan role — bagian yang
        // memang harus ada sebelum user mana pun bisa dibuat — lalu berhenti.
        // Nilai untuk test suite ada di phpunit.xml, bukan di sini.
        $adminEmail = (string) env('VMS_ADMIN_EMAIL', '');
        $adminPassword = (string) env('VMS_ADMIN_PASSWORD', '');

        if ($adminEmail !== '' && $adminPassword !== '') {
            $admin = User::firstOrCreate(
                ['email' => $adminEmail],
                [
                    'name' => env('VMS_ADMIN_NAME', 'Admin User'),
                    'password' => Hash::make($adminPassword),
                ]
            );
            $admin->syncRoles([$adminRole->name]);
        } else {
            $this->command?->warn('VMS_ADMIN_EMAIL/VMS_ADMIN_PASSWORD kosong — akun admin tidak dibuat.');
        }

        // Akun penjaga keamanan mengikuti aturan yang sama. Di produksi ia
        // dibuat lewat panel admin, bukan lewat seeder.
        $securityEmail = (string) env('VMS_SECURITY_EMAIL', '');
        $securityPassword = (string) env('VMS_SECURITY_PASSWORD', '');

        if ($securityEmail !== '' && $securityPassword !== '') {
            $security = User::firstOrCreate(
                ['email' => $securityEmail],
                [
                    'name' => env('VMS_SECURITY_NAME', 'Security Guard'),
                    'password' => Hash::make($securityPassword),
                ]
            );
            $security->syncRoles([$securityRole->name]);
        }
    }
}
