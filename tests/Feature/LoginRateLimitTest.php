<?php

use App\Models\User;

it('answers 429 after five attempts for the same login from one address', function () {
    User::factory()->create(['username' => 'POS-GERBANG-9', 'email' => null, 'password' => 'secret-pass-9']);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/login', ['login' => 'POS-GERBANG-9', 'password' => 'wrong'])->assertStatus(401);
    }

    // The right password and a different letter case still count against the same login.
    $this->postJson('/api/login', ['login' => 'pos-gerbang-9', 'password' => 'secret-pass-9'])->assertStatus(429);
});

it('counts another login separately', function () {
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/login', ['login' => 'POS-GERBANG-9', 'password' => 'wrong'])->assertStatus(401);
    }

    $this->postJson('/api/login', ['login' => 'admin@vms.local', 'password' => 'password123'])->assertOk();
});

it('caps attempts per address across different logins', function () {
    foreach (range(1, 20) as $attempt) {
        $this->postJson('/api/login', ['login' => "GATE-{$attempt}", 'password' => 'wrong'])->assertStatus(401);
    }

    $this->postJson('/api/login', ['login' => 'admin@vms.local', 'password' => 'password123'])->assertStatus(429);
});
