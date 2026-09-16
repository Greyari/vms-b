<?php

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

function registerClientIpProbe(): void
{
    Route::get('/_test/client-ip', fn (Request $request) => ['ip' => $request->ip()]);
}

it('ignores X-Forwarded-For when no proxy is trusted', function () {
    // Symfony keeps trusted proxies in static state; start from none.
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    config(['trustedproxy.proxies' => null]);
    registerClientIpProbe();

    $this->withServerVariables(['REMOTE_ADDR' => '192.168.10.50'])
        ->withHeader('X-Forwarded-For', '203.0.113.9')
        ->getJson('/_test/client-ip')
        ->assertJsonPath('ip', '192.168.10.50');
});

it('believes X-Forwarded-For when every proxy is trusted', function () {
    config(['trustedproxy.proxies' => '*']);
    registerClientIpProbe();

    try {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.4'])
            ->withHeader('X-Forwarded-For', '203.0.113.9')
            ->getJson('/_test/client-ip')
            ->assertJsonPath('ip', '203.0.113.9');
    } finally {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }
});

it('reports a healthy database without authentication', function () {
    $this->getJson('/api/health-db')->assertOk()->assertExactJson(['status' => 'ok']);
});

it('reports 503 without leaking details when the database is unreachable', function () {
    config(['database.connections.unreachable' => [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => 1,
        'database' => 'none',
        'username' => 'none',
        'password' => 'none',
    ]]);
    $default = config('database.default');
    $cache = config('cache.default');
    // Production runs CACHE_STORE=database (spec §2.5): nothing in front of this
    // probe may need the cache, or a dead database answers 500 instead of 503.
    // Forgetting the rate limiter makes a re-added throttle fail this test.
    config(['database.default' => 'unreachable', 'cache.default' => 'database']);
    app()->forgetInstance(RateLimiter::class);

    try {
        $this->getJson('/api/health-db')->assertStatus(503)->assertExactJson(['status' => 'error']);
    } finally {
        config(['database.default' => $default, 'cache.default' => $cache]);
    }
});

it('serves the SPA index for client-side routes when the build is present', function () {
    $base = tempnam(sys_get_temp_dir(), 'vms-spa-');
    $index = $base.'.html';
    file_put_contents($index, '<!doctype html><title>VMS</title><div id="root"></div>');
    config(['vms.spa_index' => $index]);

    try {
        foreach (['/', '/visits/create'] as $path) {
            $response = $this->get($path)->assertOk();
            expect($response->baseResponse)->toBeInstanceOf(BinaryFileResponse::class);
            expect($response->baseResponse->getFile()->getPathname())->toBe($index);
            expect($response->headers->get('Content-Type'))->toContain('text/html');
        }
    } finally {
        @unlink($index);
        @unlink($base);
    }
});

it('answers 404 for client-side routes when no SPA build is deployed', function () {
    config(['vms.spa_index' => sys_get_temp_dir().'/vms-no-spa-'.uniqid().'.html']);

    $this->get('/visits/create')->assertNotFound();
});

it('never answers an unknown API path with the SPA page', function () {
    $base = tempnam(sys_get_temp_dir(), 'vms-spa-');
    $index = $base.'.html';
    file_put_contents($index, '<div id="root"></div>');
    config(['vms.spa_index' => $index]);

    try {
        $this->getJson('/api/does-not-exist')->assertNotFound()->assertJsonStructure(['message']);
    } finally {
        @unlink($index);
        @unlink($base);
    }
});

it('answers the database probe while the application is in maintenance mode', function () {
    $this->artisan('down')->assertSuccessful();

    try {
        // Every other route answers 503, which proves maintenance mode is on.
        $this->postJson('/api/login', ['login' => 'nobody', 'password' => 'x'])->assertStatus(503);

        $this->getJson('/api/health-db')->assertOk()->assertExactJson(['status' => 'ok']);
    } finally {
        $this->artisan('up')->assertSuccessful();
    }
});
