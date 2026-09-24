<?php

use App\Models\User;

it('finds a username-only account in the log by a lower-case search term', function () {
    User::factory()->create(['username' => 'GATE-SAMPLE-1', 'email' => null, 'password' => 'secret-pass-9']);
    $this->postJson('/api/login', ['login' => 'gate-sample-1', 'password' => 'secret-pass-9'])->assertOk();

    actingAsAdmin();

    $this->getJson('/api/activity-logs?search=gate-sample&action=login')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.causer_username', 'GATE-SAMPLE-1');
});
