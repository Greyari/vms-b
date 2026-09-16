<?php

namespace App\Providers;

use App\Support\Username;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Login guesses: five a minute per account from one address, and a
        // looser cap per address so one counter PC cannot walk through every
        // Employee ID. Unlike /api/health-db, this limiter may sit on the
        // database cache store: signing in needs the database anyway.
        RateLimiter::for('login', function (Request $request): array {
            $typed = $request->input('login') ?? $request->input('email');
            $login = is_string($typed) ? (Username::normalize($typed) ?? '') : '';

            return [
                Limit::perMinute(5)->by('login:'.$login.'|'.$request->ip()),
                Limit::perMinute(20)->by('login-ip:'.$request->ip()),
            ];
        });
    }
}
