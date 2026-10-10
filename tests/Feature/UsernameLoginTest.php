<?php

use App\Models\ActivityLog;
use App\Models\User;

it('logs in with a username through the login field', function () {
    $user = User::factory()->create(['username' => 'POS-GERBANG-1', 'email' => null, 'password' => 'secret-pass-1']);
    $user->assignRole('security');

    $this->postJson('/api/login', ['login' => 'POS-GERBANG-1', 'password' => 'secret-pass-1'])
        ->assertOk()
        ->assertJsonPath('user.username', 'POS-GERBANG-1');
});

it('still logs in with an email through the login field', function () {
    $this->postJson('/api/login', ['login' => 'admin@vms.local', 'password' => 'password123'])->assertOk();
});

it('rejects a wrong password for a username with 401', function () {
    User::factory()->create(['username' => '100001', 'email' => null, 'password' => 'secret-pass-1']);

    $this->postJson('/api/login', ['login' => '100001', 'password' => 'nope'])->assertStatus(401);
});

it('requires either login or email', function () {
    $this->postJson('/api/login', ['password' => 'whatever'])->assertStatus(422);
});

it('lets an administrator create a username-only account', function () {
    actingAsAdmin();

    $this->postJson('/api/users', [
        'name' => 'Pos Gerbang 1',
        'username' => 'POS-GERBANG-1',
        'password' => 'changeme-123',
        'roles' => ['security'],
    ])->assertStatus(201)->assertJsonPath('username', 'POS-GERBANG-1');
});

it('rejects an account with neither username nor email', function () {
    actingAsAdmin();

    $this->postJson('/api/users', [
        'name' => 'Nobody',
        'password' => 'changeme-123',
        'roles' => ['security'],
    ])->assertStatus(422)->assertJsonValidationErrors(['username', 'email']);
});

it('rejects a new account without a role instead of crashing', function () {
    actingAsAdmin();

    $this->postJson('/api/users', [
        'name' => 'No Role',
        'email' => 'norole@vms.local',
        'password' => 'changeme-123',
    ])->assertStatus(422)->assertJsonValidationErrors(['roles']);
});

it('keeps the email when an update does not send it', function () {
    actingAsAdmin();
    $user = User::factory()->create(['email' => 'keep@vms.local']);

    $this->putJson("/api/users/{$user->id}", ['name' => 'Renamed', 'username' => 'renamed-1'])->assertOk();

    expect($user->fresh()->email)->toBe('keep@vms.local');
    expect($user->fresh()->username)->toBe('RENAMED-1');
});

it('lets a username-only user update their profile without an email', function () {
    $user = User::factory()->create(['username' => '100002', 'email' => null]);
    $user->assignRole('security');
    actingAsUser($user);

    $this->putJson('/api/profile', ['name' => 'Guard Name'])->assertOk();

    expect($user->fresh()->name)->toBe('Guard Name');
});

it('does not record an unknown login identifier when a sign-in fails', function () {
    $this->postJson('/api/login', ['login' => 'typed-a-password-here-42', 'password' => 'whatever'])->assertStatus(401);

    $entry = ActivityLog::where('action', 'login_failed')->sole();

    expect($entry->new_values)->toBe(['login' => null]);
    expect(json_encode($entry->getAttributes()))->not->toContain('typed-a-password-here-42');
});

it('records the identifier of an existing account whose sign-in fails', function () {
    User::factory()->create(['username' => 'POS-GERBANG-2', 'email' => null, 'password' => 'secret-pass-2']);

    $this->postJson('/api/login', ['login' => 'POS-GERBANG-2', 'password' => 'wrong-pass'])->assertStatus(401);

    expect(ActivityLog::where('action', 'login_failed')->sole()->new_values)->toBe(['login' => 'POS-GERBANG-2']);
});

it('rejects an update that clears both username and email', function () {
    actingAsAdmin();
    $user = User::factory()->create(['email' => 'both@vms.local']);

    $this->putJson("/api/users/{$user->id}", ['name' => $user->name, 'username' => null, 'email' => null])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['username', 'email']);

    expect($user->fresh()->email)->toBe('both@vms.local');
});

it('lets a user update their username through profile and upper-cases it', function () {
    $user = User::factory()->create(['username' => 'OLDUSER', 'email' => 'user@vms.local']);
    $user->assignRole('security');
    actingAsUser($user);

    $this->putJson('/api/profile', ['name' => $user->name, 'username' => 'newuser99'])->assertOk();

    expect($user->fresh()->username)->toBe('NEWUSER99');
});

it('rejects a duplicate username on profile update', function () {
    User::factory()->create(['username' => 'TAKENUSER']);
    $user = User::factory()->create(['username' => 'MYUSER', 'email' => 'my@vms.local']);
    $user->assignRole('security');
    actingAsUser($user);

    $this->putJson('/api/profile', ['name' => $user->name, 'username' => 'takenuser'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['username']);
});
