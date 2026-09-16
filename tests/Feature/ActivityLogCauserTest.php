<?php

use App\Models\ActivityLog;
use App\Models\User;

it('records the username of a username-only causer', function () {
    $user = User::factory()->create(['username' => 'POS-GERBANG-7', 'email' => null]);
    $user->assignRole('security');
    actingAsUser($user);

    $this->postJson('/api/logout')->assertOk();

    $entry = ActivityLog::where('action', 'logout')->sole();
    expect($entry->causer_username)->toBe('POS-GERBANG-7');
    expect($entry->causer_email)->toBeNull();
});

it('finds activity by the causer username', function () {
    $user = User::factory()->create(['username' => 'POS-GERBANG-7', 'email' => null]);
    $user->assignRole('security');
    actingAsUser($user);
    $this->postJson('/api/logout')->assertOk();

    actingAsAdmin();
    $response = $this->getJson('/api/activity-logs?search=GERBANG-7');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('causer_username'))->toContain('POS-GERBANG-7');
});
