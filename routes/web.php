<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

// The SPA owns every non-API path; see SpaController. A controller rather than
// a closure keeps `php artisan route:cache` working in the deploy script.
Route::get('/', SpaController::class);
Route::fallback(SpaController::class);
