<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('administrator');
    }

    public function test_admin_can_update_custom_role_name_and_permissions(): void
    {
        $role = Role::create(['name' => 'operator_khusus']);
        $role->syncPermissions(['view_visits']);

        $res = $this->actingAs($this->admin)->putJson("/api/roles/{$role->id}", [
            'name' => 'operator_lapangan',
            'permissions' => ['view_visits', 'create_visits'],
        ]);

        $res->assertStatus(200);
        $this->assertSame('operator_lapangan', $res->json()['name']);
        $this->assertTrue($role->fresh()->hasPermissionTo('create_visits'));
    }

    public function test_admin_can_update_builtin_role_permissions(): void
    {
        $securityRole = Role::findByName('security');

        $res = $this->actingAs($this->admin)->putJson("/api/roles/{$securityRole->id}", [
            'permissions' => ['view_visits', 'create_visits', 'export_visits'],
        ]);

        $res->assertStatus(200);
        $this->assertSame('security', $res->json()['name']);
        $this->assertTrue($securityRole->fresh()->hasPermissionTo('export_visits'));
    }
}
