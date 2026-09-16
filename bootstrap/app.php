<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Proxy yang dipercaya diatur di config/trustedproxy.php lewat
        // TRUSTED_PROXIES, bukan di sini. Server lokal di LAN mengosongkannya
        // karena Apache menerima permintaan langsung dari PC konter; Azure
        // mengisinya '*' karena hostname App Service hanya bisa dicapai lewat
        // proksi Static Web Apps.

        // deploy.ps1 runs `php artisan down`, migrates, restarts Apache and only
        // then probes /api/health-db before `php artisan up` (spec §2.7), so the
        // probe must keep answering while the application is in maintenance mode.
        $middleware->preventRequestsDuringMaintenance(except: ['api/health-db']);

        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (UnauthorizedException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                ], 403);
            }
        });
    })->create();
