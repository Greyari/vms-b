<?php

use App\Models\Role;

/*
 * Decision FE-3-ROLE (2026-09-28): the three built-in roles are read-only. RoleAndPermissionSeeder
 * syncs their permissions on every run, so an edit made in the UI would silently vanish at the next
 * seed; the API refuses it instead, and the SPA shows these roles read-only.
 */

it('refuses to change a built-in role', function (string $name) {
    actingAsAdmin();
    $role = Role::findByName($name);
    $before = $role->permissions->pluck('name')->sort()->values()->all();

    $this->putJson("/api/roles/{$role->id}", ['name' => $name, 'permissions' => ['view_visits']])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Built-in roles cannot be changed or deleted.');

    expect($role->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe($before);
})->with(['administrator', 'supervisor', 'security']);

it('refuses to delete a built-in role', function (string $name) {
    actingAsAdmin();
    $role = Role::findByName($name);

    $this->deleteJson("/api/roles/{$role->id}")->assertStatus(422);

    expect(Role::where('name', $name)->exists())->toBeTrue();
})->with(['administrator', 'supervisor', 'security']);

it('still lets an admin edit and delete a role they created', function () {
    actingAsAdmin();
    $role = Role::create(['name' => 'gate trainee']);

    $this->putJson("/api/roles/{$role->id}", ['name' => 'gate trainee', 'permissions' => ['view_visits']])
        ->assertOk()
        ->assertJsonPath('is_builtin', false);

    $this->deleteJson("/api/roles/{$role->id}")->assertOk();
    expect(Role::where('name', 'gate trainee')->exists())->toBeFalse();
});

it('creates a role through the API on the guard the seeded permissions use', function () {
    actingAsAdmin();

    $this->postJson('/api/roles', ['name' => 'gate trainee', 'permissions' => ['view_visits']])
        ->assertCreated()
        ->assertJsonPath('is_builtin', false);

    expect(Role::where('name', 'gate trainee')->value('guard_name'))->toBe('web');
});

it('marks which roles are built in', function () {
    actingAsAdmin();
    Role::create(['name' => 'gate trainee']);

    $flags = collect($this->getJson('/api/roles')->assertOk()->json())->pluck('is_builtin', 'name');

    expect($flags->all())->toBe([
        'administrator' => true,
        'security' => true,
        'supervisor' => true,
        'gate trainee' => false,
    ]);
});
