<?php

use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\VehicleCardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\VisitController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ProfileController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    // Profile Routes
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword']);

    // Visit Routes
    Route::get('/visitors/check-ktp/{ktp}', [VisitController::class, 'checkKtp']);
    Route::get('/visitors/check-rfid/{uid}', [VisitController::class, 'checkRfid']);
    Route::get('/visits/search', [VisitController::class, 'searchVisitor']);
    Route::get('/visits/active', [VisitController::class, 'activeVisits']);
    Route::get('/visits/history', [VisitController::class, 'history']);
    Route::get('/visits/export', [VisitController::class, 'export']);
    Route::get('/visits/export-pdf', [VisitController::class, 'exportPdf']);
    Route::get('/visits/{id}/print', [VisitController::class, 'print']);
    Route::post('/visits', [VisitController::class, 'store']);
    Route::post('/visits/{id}/checkout', [VisitController::class, 'checkout']);
    Route::delete('/visits/{id}', [VisitController::class, 'destroy']);

    // Master Data Routes
    Route::get('/staffs/template/download', [StaffController::class, 'downloadTemplate']);
    Route::post('/staffs/import', [StaffController::class, 'import']);
    Route::get('/staffs/trashed', [StaffController::class, 'trashed']);
    Route::post('/staffs/{id}/restore', [StaffController::class, 'restore']);
    Route::apiResource('staffs', StaffController::class);

    Route::get('/cards/template/download', [CardController::class, 'downloadTemplate']);
    Route::post('/cards/import', [CardController::class, 'import']);
    Route::get('/cards/trashed', [CardController::class, 'trashed']);
    Route::post('/cards/{id}/restore', [CardController::class, 'restore']);
    Route::apiResource('cards', CardController::class);

    Route::get('/vehicle-cards/template/download', [VehicleCardController::class, 'downloadTemplate']);
    Route::post('/vehicle-cards/import', [VehicleCardController::class, 'import']);
    Route::get('/vehicle-cards/trashed', [VehicleCardController::class, 'trashed']);
    Route::post('/vehicle-cards/{id}/restore', [VehicleCardController::class, 'restore']);
    Route::apiResource('vehicle-cards', VehicleCardController::class);

    // System Settings Routes
    Route::get('/permissions', [PermissionController::class, 'index']);
    Route::apiResource('roles', RoleController::class);
    Route::apiResource('users', UserController::class);
});
