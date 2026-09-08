<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VehicleCardController;
use App\Http\Controllers\Api\VisitController;
use App\Http\Controllers\Api\VisitorController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    // Auth-only (no permission required) — identity, session, self-service profile.
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword']);

    // --- Visits (read) --------------------------------------------------
    Route::middleware('permission:view_visits')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/visitors/check-ktp/{ktp}', [VisitController::class, 'checkKtp']);
        Route::get('/visits/search', [VisitController::class, 'searchVisitor']);
        Route::get('/visits/active', [VisitController::class, 'activeVisits']);
        Route::get('/visits/history', [VisitController::class, 'history']);
        Route::get('/visits/{id}/print', [VisitController::class, 'print']);
    });

    // --- Visits (write) -------------------------------------------------
    Route::get('/visits/export', [VisitController::class, 'export'])->middleware('permission:export_visits');
    Route::get('/visits/export-pdf', [VisitController::class, 'exportPdf'])->middleware('permission:export_visits');
    Route::post('/visits', [VisitController::class, 'store'])->middleware('permission:create_visits');
    Route::post('/visits/{id}/checkout', [VisitController::class, 'checkout'])->middleware('permission:checkout_visits');
    Route::delete('/visits/{id}', [VisitController::class, 'destroy'])->middleware('permission:delete_visits');

    // --- Visitors -------------------------------------------------------
    Route::get('/visitors', [VisitorController::class, 'index'])->middleware('permission:view_visitors');
    Route::get('/visitors/blacklisted', [VisitorController::class, 'blacklisted'])->middleware('permission:view_visitors');

    Route::middleware('permission:manage_visitors')->group(function () {
        Route::get('/visitors/export', [VisitorController::class, 'export']);
        Route::get('/visitors/template', [VisitorController::class, 'downloadTemplate']);
        Route::post('/visitors/import', [VisitorController::class, 'import']);
        Route::put('/visitors/{id}', [VisitorController::class, 'update']);
        Route::delete('/visitors/{id}', [VisitorController::class, 'destroy']);
    });

    Route::middleware('permission:manage_blacklist')->group(function () {
        Route::get('/visitors/blacklisted/export', [VisitorController::class, 'exportBlacklist']);
        Route::get('/visitors/blacklisted/template', [VisitorController::class, 'downloadBlacklistTemplate']);
        Route::post('/visitors/blacklisted/import', [VisitorController::class, 'importBlacklist']);
        Route::post('/visitors/{id}/blacklist', [VisitorController::class, 'blacklist']);
        Route::post('/visitors/{id}/unblacklist', [VisitorController::class, 'unblacklist']);
    });

    // --- Staffs ---------------------------------------------------------
    // Specific routes must precede the apiResource so they aren't captured by {staff}.
    Route::middleware('permission:manage_staffs')->group(function () {
        Route::get('/staffs/template/download', [StaffController::class, 'downloadTemplate']);
        Route::post('/staffs/import', [StaffController::class, 'import']);
        Route::get('/staffs/trashed', [StaffController::class, 'trashed']);
        Route::post('/staffs/{id}/restore', [StaffController::class, 'restore']);
    });
    Route::apiResource('staffs', StaffController::class)->only(['index', 'show'])->middleware('permission:view_staffs');
    Route::apiResource('staffs', StaffController::class)->only(['store', 'update', 'destroy'])->middleware('permission:manage_staffs');

    // --- Cards ----------------------------------------------------------
    Route::middleware('permission:manage_cards')->group(function () {
        Route::get('/cards/template/download', [CardController::class, 'downloadTemplate']);
        Route::post('/cards/import', [CardController::class, 'import']);
        Route::get('/cards/trashed', [CardController::class, 'trashed']);
        Route::post('/cards/{id}/restore', [CardController::class, 'restore']);
    });
    Route::apiResource('cards', CardController::class)->only(['index', 'show'])->middleware('permission:view_cards');
    Route::apiResource('cards', CardController::class)->only(['store', 'update', 'destroy'])->middleware('permission:manage_cards');

    // --- Vehicle cards --------------------------------------------------
    Route::middleware('permission:manage_cards')->group(function () {
        Route::get('/vehicle-cards/template/download', [VehicleCardController::class, 'downloadTemplate']);
        Route::post('/vehicle-cards/import', [VehicleCardController::class, 'import']);
        Route::get('/vehicle-cards/trashed', [VehicleCardController::class, 'trashed']);
        Route::post('/vehicle-cards/{id}/restore', [VehicleCardController::class, 'restore']);
    });
    Route::apiResource('vehicle-cards', VehicleCardController::class)->only(['index', 'show'])->middleware('permission:view_cards');
    Route::apiResource('vehicle-cards', VehicleCardController::class)->only(['store', 'update', 'destroy'])->middleware('permission:manage_cards');

    // --- System settings ------------------------------------------------
    Route::get('/permissions', [PermissionController::class, 'index'])->middleware('permission:view_roles');
    Route::apiResource('roles', RoleController::class)->only(['index', 'show'])->middleware('permission:view_roles');
    Route::apiResource('roles', RoleController::class)->only(['store', 'update', 'destroy'])->middleware('permission:manage_roles');

    Route::apiResource('users', UserController::class)->only(['index', 'show'])->middleware('permission:view_users');
    Route::apiResource('users', UserController::class)->only(['store', 'update', 'destroy'])->middleware('permission:manage_users');

    // --- Activity logs ---------------------------------------------------
    Route::middleware('permission:view_logs')->group(function () {
        Route::get('/activity-logs', [ActivityLogController::class, 'index']);
        Route::get('/activity-logs/subject-types', [ActivityLogController::class, 'subjectTypes']);
    });
});
