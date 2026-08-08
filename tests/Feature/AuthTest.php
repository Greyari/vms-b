<?php

it('logs an admin in and returns a token, roles and effective permissions', function () {
    $response = $this->postJson('/api/login', [
        'email' => 'admin@vms.local',
        'password' => 'password123',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'access_token',
            'token_type',
            'user' => ['id', 'name', 'email', 'roles', 'permissions'],
        ]);

    expect($response->json('access_token'))->not->toBeEmpty();

    $roleNames = collect($response->json('user.roles'))->pluck('name');
    expect($roleNames)->toContain('administrator');

    $permissionNames = collect($response->json('user.permissions'))->pluck('name');
    expect($permissionNames)->not->toBeEmpty();
    expect($permissionNames)->toContain('manage_users');
});

it('rejects bad credentials with a 401', function () {
    $this->postJson('/api/login', [
        'email' => 'admin@vms.local',
        'password' => 'wrong-password',
    ])->assertStatus(401)
        ->assertJson(['message' => 'Invalid credentials']);
});

it('returns the authenticated user with roles and permissions from /user', function () {
    actingAsAdmin();

    $response = $this->getJson('/api/user');

    $response->assertOk()
        ->assertJsonStructure(['id', 'name', 'email', 'roles', 'permissions']);

    $permissionNames = collect($response->json('permissions'))->pluck('name');
    expect($permissionNames)->toContain('manage_users');
    expect(collect($response->json('roles'))->pluck('name'))->toContain('administrator');
});

it('blocks unauthenticated access to /user with a 401', function () {
    $this->getJson('/api/user')->assertStatus(401);
});
