<?php

use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| All Feature tests run against sqlite :memory: (see phpunit.xml) with a
| fresh schema per test. Before each test we seed roles/permissions, flush
| the spatie permission cache, and force the Master Data integration off so
| no test performs a real outbound HTTP call unless it opts in with
| Http::fake().
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['services.master_data.enabled' => false]);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function adminUser(): User
{
    return User::where('email', 'admin@vms.local')->firstOrFail();
}

function securityUser(): User
{
    return User::where('email', 'security@vms.local')->firstOrFail();
}

/** Authenticate the given user against the sanctum guard with all abilities. */
function actingAsUser(User $user): User
{
    Sanctum::actingAs($user, ['*']);

    return $user;
}

function actingAsAdmin(): User
{
    return actingAsUser(adminUser());
}

function actingAsSecurity(): User
{
    return actingAsUser(securityUser());
}
