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
        // Di Azure aplikasi ini hanya dicapai lewat Static Web Apps, yang
        // memproksi /api/* ke App Service. Tanpa mempercayai proksi itu setiap
        // permintaan tampak datang dari alamat yang sama: kolom IP di
        // activity_logs mencatat proksi Azure alih-alih pengunjung, dan seluruh
        // rate limit runtuh jadi satu ember bersama.
        //
        // '*' benar di topologi ini karena hostname App Service menolak akses
        // langsung (400 Bad Request dari identity provider "Azure Static Web
        // Apps (Linked)" yang terpasang saat backend ditaut), jadi tidak ada
        // jalan mengirim X-Forwarded-For karangan tanpa lewat proksi. Kalau
        // tautan backend itu dilepas, baris ini harus ditinjau ulang.
        $middleware->trustProxies(at: '*');

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
