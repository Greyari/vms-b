<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => bcrypt('password123'),
            'is_active' => false,
        ]);
        $user->assignRole('security');

        $response = $this->postJson('/api/login', [
            'login' => 'inactive@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Akun Anda berstatus nonaktif. Silakan hubungi administrator.',
        ]);
    }

    public function test_active_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'active@example.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);
        $user->assignRole('security');

        $response = $this->postJson('/api/login', [
            'login' => 'active@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $this->assertArrayHasKey('access_token', $response->json());
    }

    public function test_admin_can_update_user_active_status(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $target = User::factory()->create(['is_active' => true]);
        $target->assignRole('security');

        $response = $this->actingAs($admin)->putJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'is_active' => false,
            'roles' => ['security'],
        ]);

        $response->assertStatus(200);
        $this->assertFalse($target->fresh()->is_active);
    }
}
