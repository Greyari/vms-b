<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

it('stores a username upper-cased whichever way it was typed', function () {
    $user = User::factory()->create(['username' => ' pos-gerbang-5 ', 'email' => null]);

    expect($user->fresh()->username)->toBe('POS-GERBANG-5');
});

it('signs in with a username typed in lower case', function () {
    User::factory()->create(['username' => 'POS-GERBANG-5', 'email' => null, 'password' => 'secret-pass-5']);

    $this->postJson('/api/login', ['login' => 'pos-gerbang-5', 'password' => 'secret-pass-5'])
        ->assertOk()
        ->assertJsonPath('user.username', 'POS-GERBANG-5');
});

it('rejects a new account whose username differs from an existing one only by case', function () {
    User::factory()->create(['username' => 'POS-GERBANG-5', 'email' => null]);
    actingAsAdmin();

    $this->postJson('/api/users', [
        'name' => 'Duplicate Post',
        'username' => 'pos-gerbang-5',
        'password' => 'changeme-123',
        'roles' => ['security'],
    ])->assertStatus(422)->assertJsonValidationErrors(['username']);
});

it('upper-cases a username sent on update', function () {
    actingAsAdmin();
    $user = User::factory()->create(['email' => 'case@vms.local']);

    $this->putJson("/api/users/{$user->id}", ['name' => $user->name, 'username' => 'gate-7'])->assertOk();

    expect($user->fresh()->username)->toBe('GATE-7');
});

it('upper-cases stored usernames and logs an error for a clashing row it leaves untouched', function () {
    Log::spy();

    $row = fn (string $name, string $username) => [
        'id' => (string) Str::uuid7(),
        'name' => $name,
        'username' => $username,
        'password' => 'not-a-real-hash',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $holder = $row('B', 'GATE-B');
    $clash = $row('C', 'gate-b');

    DB::table('users')->insert([$row('A', 'gate-a'), $holder, $clash]);

    (require database_path('migrations/2026_09_15_100100_normalize_usernames.php'))->up();

    expect(DB::table('users')->where('name', 'A')->value('username'))->toBe('GATE-A');
    expect(DB::table('users')->where('name', 'C')->value('username'))->toBe('gate-b');

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'cannot sign in by username')
            && $context === ['user_id' => $clash['id'], 'conflicting_user_id' => $holder['id']]);
    Log::shouldNotHaveReceived('warning');
});
