<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use Database\Seeders\RoleAndPermissionSeeder;

$sorted = fn (array $names): array => collect($names)->sort()->values()->all();

it('grants security and supervisor exactly the approved permissions', function () use ($sorted) {
    $permissionsOf = fn (string $role): array => $sorted(Role::findByName($role)->permissions->pluck('name')->all());

    expect($permissionsOf('security'))->toBe($sorted([
        'checkout_visits', 'create_visitors', 'create_visits', 'update_visits', 'view_cards',
        'view_locations', 'view_staffs', 'view_visitors', 'view_visits',
    ]));

    expect($permissionsOf('supervisor'))->toBe($sorted([
        'checkout_visits', 'create_visitors', 'create_visits', 'export_visits', 'manage_blacklist',
        'manage_cards', 'manage_locations', 'manage_staffs', 'manage_vessel_arrivals', 'manage_visitors',
        'update_visits', 'view_cards', 'view_locations', 'view_reports', 'view_staffs',
        'view_vessel_arrivals', 'view_visitors', 'view_visits',
    ]));
});

it('gives the administrator every new permission', function () {
    $admin = Role::findByName('administrator');

    foreach (['update_visits', 'create_visitors', 'view_locations', 'manage_locations', 'view_vessel_arrivals', 'manage_vessel_arrivals', 'view_reports'] as $permission) {
        expect($admin->hasPermissionTo($permission))->toBeTrue();
    }
});

it('keeps supervisors out of user management', function () {
    $user = User::factory()->create();
    $user->assignRole('supervisor');
    actingAsUser($user);

    $this->getJson('/api/users')->assertStatus(403);
});

it('stays the same when the seeder runs twice', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    expect(Role::findByName('supervisor')->permissions)->toHaveCount(18);
    expect(Role::findByName('security')->permissions)->toHaveCount(9);
});

it('lets a supervisor manage staff but not delete visits or create users', function () {
    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');
    $visit = Visit::factory()->create();
    actingAsUser($supervisor);

    $this->postJson('/api/staffs', ['staff_id' => 'STF-SUP-1', 'name' => 'Supervisor Made', 'department' => 'Ops'])
        ->assertStatus(201);
    $this->deleteJson("/api/visits/{$visit->id}")->assertStatus(403);
    $this->postJson('/api/users', [
        'name' => 'Not Allowed',
        'username' => 'NOT-ALLOWED',
        'password' => 'changeme-123',
        'roles' => ['security'],
    ])->assertStatus(403);
});

it('keeps security out of visitor and card management', function () {
    $visitor = Visitor::factory()->create();
    actingAsSecurity();

    $this->putJson("/api/visitors/{$visitor->id}", [
        'id_type' => 'ktp',
        'id_number' => $visitor->id_number,
        'name' => 'Renamed',
    ])->assertStatus(403);
    $this->postJson('/api/cards', ['card_no' => 'VC-SEC-1', 'status' => 'available'])->assertStatus(403);
});
