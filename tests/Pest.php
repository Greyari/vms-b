<?php

use App\Models\User;
use App\Models\Visitor;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

/**
 * Skip unless the suite runs on PostgreSQL. SQLite stays the fast default
 * (phpunit.xml); the pgsql group covers what SQLite cannot check: PG-only SQL
 * and real migration behaviour. Run it with DB_CONNECTION=pgsql (plan header).
 */
function requirePostgres(): void
{
    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Needs PostgreSQL (DB_CONNECTION=pgsql).');
    }
}

/**
 * Register a Visitor::creating listener that simulates a lost visitor_no
 * allocation race: it runs after the model's own creating hook (registered
 * earlier, in Visitor::booted()), so $visitor->visitor_no is already set, and
 * inserts a decoy row carrying that same number under a different identity —
 * via the query builder, so it does not itself fire any model events. That
 * decoy is written inside the same transaction as the attempt, so when the
 * attempt's own (real) insert then collides on the visitor_no unique index,
 * rolling back the transaction removes the decoy too and the next attempt's
 * nextVisitorNo() recomputes cleanly.
 *
 * $onCreating receives the Visitor being created; it decides (by its own
 * logic) whether to insert the colliding decoy for that call.
 */
function raceVisitorNoOnCreating(callable $onCreating): void
{
    // Visitor may not have booted yet in this test (nothing before this point
    // touched it) — booting registers its OWN creating listeners (HasUuidV7's
    // id assignment, then visitor_no assignment in Visitor::booted()). Force
    // that now, before we add ours, so ours is guaranteed to run after them
    // (Eloquent's "creating" event calls listeners in registration order) and
    // sees $visitor->visitor_no already set. new Visitor() only boots the
    // class — it never touches the database.
    new Visitor;

    Visitor::creating(function (Visitor $visitor) use ($onCreating): void {
        $onCreating($visitor);
    });
}
