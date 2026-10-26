<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AppConfigController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GlobalBlacklistController;
use App\Http\Controllers\Api\HealthDbController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\MailTestController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\PreRegistrationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VehicleCardController;
use App\Http\Controllers\Api\VesselArrivalController;
use App\Http\Controllers\Api\VisitController;
use App\Http\Controllers\Api\VisitorController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
// No throttle: production caches in the database (CACHE_STORE=database), so a
// rate limiter here would turn "database down" into a 500 before this probe
// could answer 503.
Route::get('/health-db', HealthDbController::class);

// Per-yard branding for the one SPA build (decision PE-7). Public, nothing secret.
Route::get('/app-config', AppConfigController::class);

Route::middleware('auth:sanctum')->group(function () {
    // Auth-only (no permission required) — identity, session, self-service profile.
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword']);

    // --- Lookups: form suggestions, open to every signed-in user ---------
    Route::prefix('lookups')->group(function () {
        Route::get('/nationalities', [LookupController::class, 'nationalities']);
        Route::get('/companies', [LookupController::class, 'companies']);
        Route::get('/purposes', [LookupController::class, 'purposes']);
        Route::get('/card-types', [LookupController::class, 'cardTypes']);
        Route::get('/visit-form-data', [LookupController::class, 'visitFormData']);
    });

    // --- Visits (read) --------------------------------------------------
    Route::middleware('permission:view_visits')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/visitors/check-ktp/{ktp}', [VisitController::class, 'checkKtp']);
        Route::get('/visits', [VisitController::class, 'index']);
        Route::get('/visits/search', [VisitController::class, 'searchVisitor']);
        Route::get('/visits/active', [VisitController::class, 'activeVisits']);
        Route::get('/visits/reprint', [VisitController::class, 'reprint']);
        Route::get('/visits/{id}/print', [VisitController::class, 'print']);
    });

    // --- Reports --------------------------------------------------------
    Route::middleware('permission:view_reports')->group(function () {
        Route::get('/reports/visits', [ReportController::class, 'visits']);
        Route::get('/visits/history', [ReportController::class, 'visits']);
        Route::get('/reports/vessels', [ReportController::class, 'vessels']);
        Route::get('/reports/crew', [ReportController::class, 'crew']);

        Route::middleware('permission:export_visits')->group(function () {
            Route::get('/reports/visits/export', [ReportController::class, 'exportVisits']);
            Route::get('/reports/visits/pdf', [ReportController::class, 'exportVisitsPdf']);
            Route::get('/visits/export', [ReportController::class, 'exportVisits']);
            Route::get('/visits/export-pdf', [ReportController::class, 'exportVisitsPdf']);
            Route::get('/reports/vessels/export', [ReportController::class, 'exportVessels']);
            Route::get('/reports/vessels/pdf', [ReportController::class, 'exportVesselsPdf']);
            Route::get('/reports/crew/export', [ReportController::class, 'exportCrew']);
            Route::get('/reports/crew/pdf', [ReportController::class, 'exportCrewPdf']);
        });
    });

    // --- Visits (write) -------------------------------------------------
    Route::post('/visits', [VisitController::class, 'store'])->middleware('permission:create_visits');

    // Hybrid multi-yard (spec §6.2, §6.3, §8.3): scanned pre-registrations, the global blacklist
    // mirror and its outbox, and the sync indicator.
    Route::get('/pre-registrations/{token}', [PreRegistrationController::class, 'show'])->middleware('permission:create_visits');
    Route::get('/global-blacklists', [GlobalBlacklistController::class, 'index'])->middleware('permission:view_visitors');
    Route::middleware('permission:manage_blacklist')->group(function () {
        Route::get('/global-blacklists/outbox', [GlobalBlacklistController::class, 'outbox']);
        Route::post('/global-blacklists', [GlobalBlacklistController::class, 'store']);
        Route::post('/global-blacklists/{id}/revoke', [GlobalBlacklistController::class, 'revoke'])->whereUuid('id');
        Route::post('/sync/run', [SyncController::class, 'run']);
    });
    Route::get('/sync/status', [SyncController::class, 'status']);
    Route::put('/visits/{id}', [VisitController::class, 'update'])->middleware('permission:update_visits')->whereUuid('id');
    Route::post('/visits/bulk-checkout', [VisitController::class, 'bulkCheckout'])->middleware('permission:checkout_visits');
    Route::post('/visits/{id}/checkout', [VisitController::class, 'checkout'])->middleware('permission:checkout_visits');
    Route::delete('/visits/{id}', [VisitController::class, 'destroy'])->middleware('permission:delete_visits');

    // --- Visitors -------------------------------------------------------
    Route::get('/visitors', [VisitorController::class, 'index'])->middleware('permission:view_visitors');
    Route::get('/visitors/search', [VisitorController::class, 'search'])->middleware('permission:view_visitors');
    Route::get('/visitors/blacklisted', [VisitorController::class, 'blacklisted'])->middleware('permission:view_visitors');
    Route::get('/visitors/{id}', [VisitorController::class, 'show'])->middleware('permission:view_visitors')->whereUuid('id');
    Route::post('/visitors', [VisitorController::class, 'store'])->middleware('permission:create_visitors');
    Route::get('/visitors/{id}/visits', [VisitorController::class, 'visits'])->middleware('permission:view_visitors')->whereUuid('id');

    Route::middleware('permission:manage_visitors')->group(function () {
        Route::get('/visitors/export', [VisitorController::class, 'export']);
        Route::get('/visitors/export/count', [VisitorController::class, 'exportCount']);
        Route::get('/visitors/export-count', [VisitorController::class, 'exportCount']);
        Route::get('/visitors/template', [VisitorController::class, 'downloadTemplate']);
        Route::get('/visitors/template/download', [VisitorController::class, 'downloadTemplate']);
        Route::post('/visitors/import-preview', [VisitorController::class, 'importPreview']);
        Route::post('/visitors/import', [VisitorController::class, 'import']);
        Route::put('/visitors/{id}', [VisitorController::class, 'update']);
        Route::delete('/visitors/{id}', [VisitorController::class, 'destroy']);
    });

    Route::middleware('permission:manage_blacklist')->group(function () {
        Route::get('/visitors/blacklisted/export', [VisitorController::class, 'exportBlacklist']);
        Route::get('/visitors/blacklisted/export/count', [VisitorController::class, 'exportBlacklistCount']);
        Route::get('/visitors/blacklisted/export-count', [VisitorController::class, 'exportBlacklistCount']);
        Route::get('/visitors/blacklisted/template', [VisitorController::class, 'downloadBlacklistTemplate']);
        Route::get('/visitors/blacklisted/template/download', [VisitorController::class, 'downloadBlacklistTemplate']);
        Route::post('/visitors/blacklisted/import-preview', [VisitorController::class, 'importBlacklistPreview']);
        Route::post('/visitors/blacklisted/import', [VisitorController::class, 'importBlacklist']);
        Route::post('/visitors/blacklisted', [VisitorController::class, 'storeBlacklist']);
        Route::put('/visitors/blacklisted/{id}', [VisitorController::class, 'updateBlacklist'])->whereUuid('id');
        Route::post('/visitors/blacklisted/{id}/connect', [VisitorController::class, 'connectBlacklist'])->whereUuid('id');
        Route::post('/visitors/blacklisted/{id}/unblacklist', [VisitorController::class, 'unblacklist'])->whereUuid('id');
        Route::post('/visitors/{id}/blacklist', [VisitorController::class, 'blacklist']);
        Route::post('/visitors/{id}/unblacklist', [VisitorController::class, 'unblacklist']);
    });

    // --- Staffs ---------------------------------------------------------
    // Specific routes must precede the apiResource so they aren't captured by {staff}.
    Route::middleware('permission:manage_staffs')->group(function () {
        Route::get('/staffs/template', [StaffController::class, 'downloadTemplate']);
        Route::get('/staffs/template/download', [StaffController::class, 'downloadTemplate']);
        Route::get('/staffs/export', [StaffController::class, 'export']);
        Route::get('/staffs/export/count', [StaffController::class, 'exportCount']);
        Route::get('/staffs/export-count', [StaffController::class, 'exportCount']);
        Route::post('/staffs/import-preview', [StaffController::class, 'importPreview']);
        Route::post('/staffs/import', [StaffController::class, 'import']);
        Route::get('/staffs/trashed', [StaffController::class, 'trashed']);
        Route::post('/staffs/{id}/restore', [StaffController::class, 'restore']);
    });
    Route::apiResource('staffs', StaffController::class)->only(['index', 'show'])->middleware('permission:view_staffs');
    Route::apiResource('staffs', StaffController::class)->only(['store', 'update', 'destroy'])->middleware('permission:manage_staffs');

    // --- Cards ----------------------------------------------------------
    Route::middleware('permission:manage_cards')->group(function () {
        Route::get('/cards/template', [CardController::class, 'downloadTemplate']);
        Route::get('/cards/template/download', [CardController::class, 'downloadTemplate']);
        Route::get('/cards/export', [CardController::class, 'export']);
        Route::get('/cards/export/count', [CardController::class, 'exportCount']);
        Route::get('/cards/export-count', [CardController::class, 'exportCount']);
        Route::post('/cards/import-preview', [CardController::class, 'importPreview']);
        Route::post('/cards/import', [CardController::class, 'import']);
        Route::get('/cards/trashed', [CardController::class, 'trashed']);
        Route::post('/cards/{id}/restore', [CardController::class, 'restore']);
    });
    Route::apiResource('cards', CardController::class)->only(['index', 'show'])->middleware('permission:view_cards');
    Route::apiResource('cards', CardController::class)->only(['store', 'update', 'destroy'])->middleware('permission:manage_cards');

    // --- Vehicle cards --------------------------------------------------
    Route::middleware('permission:manage_cards')->group(function () {
        Route::get('/vehicle-cards/template', [VehicleCardController::class, 'downloadTemplate']);
        Route::get('/vehicle-cards/template/download', [VehicleCardController::class, 'downloadTemplate']);
        Route::get('/vehicle-cards/export', [VehicleCardController::class, 'export']);
        Route::get('/vehicle-cards/export/count', [VehicleCardController::class, 'exportCount']);
        Route::get('/vehicle-cards/export-count', [VehicleCardController::class, 'exportCount']);
        Route::post('/vehicle-cards/import-preview', [VehicleCardController::class, 'importPreview']);
        Route::post('/vehicle-cards/import', [VehicleCardController::class, 'import']);
        Route::get('/vehicle-cards/trashed', [VehicleCardController::class, 'trashed']);
        Route::post('/vehicle-cards/{id}/restore', [VehicleCardController::class, 'restore']);
    });
    Route::apiResource('vehicle-cards', VehicleCardController::class)->only(['index', 'show'])->middleware('permission:view_cards');
    Route::apiResource('vehicle-cards', VehicleCardController::class)->only(['store', 'update', 'destroy'])->middleware('permission:manage_cards');

    // --- Locations and vessels -------------------------------------------
    Route::middleware('permission:view_locations')->group(function () {
        Route::get('/locations', [LocationController::class, 'index']);
        Route::get('/locations/{id}', [LocationController::class, 'show'])->whereUuid('id');
    });
    Route::middleware('permission:manage_locations')->group(function () {
        Route::get('/locations/template', [LocationController::class, 'downloadTemplate']);
        Route::get('/locations/template/download', [LocationController::class, 'downloadTemplate']);
        Route::get('/locations/export', [LocationController::class, 'export']);
        Route::get('/locations/export/count', [LocationController::class, 'exportCount']);
        Route::get('/locations/export-count', [LocationController::class, 'exportCount']);
        Route::post('/locations/import-preview', [LocationController::class, 'importPreview']);
        Route::post('/locations/import', [LocationController::class, 'import']);
        Route::post('/locations', [LocationController::class, 'store']);
        Route::put('/locations/{id}', [LocationController::class, 'update'])->whereUuid('id');
        Route::delete('/locations/{id}', [LocationController::class, 'destroy'])->whereUuid('id');
    });

    // --- Vessel arrivals ------------------------------------------------
    Route::middleware('permission:view_vessel_arrivals')->group(function () {
        Route::get('/vessel-arrivals/search', [VesselArrivalController::class, 'search']);
        Route::get('/vessel-arrivals/crew/{visitorId}/placement-detail', [VesselArrivalController::class, 'crewPlacementDetail'])->whereUuid('visitorId');
        Route::get('/vessel-arrivals', [VesselArrivalController::class, 'index']);
        Route::get('/vessel-arrivals/crew-candidates', [VesselArrivalController::class, 'crewCandidates']);
        Route::get('/vessel-arrivals/{id}/crew-logs', [VesselArrivalController::class, 'crewLogs'])->whereUuid('id');
        Route::get('/vessel-arrivals/{id}/crew-logs/export', [VesselArrivalController::class, 'exportCrewLogs'])->whereUuid('id');
        Route::get('/vessel-arrivals/{id}', [VesselArrivalController::class, 'show'])->whereUuid('id');
    });
    Route::middleware('permission:manage_vessel_arrivals')->group(function () {
        Route::post('/vessel-arrivals', [VesselArrivalController::class, 'store']);
        Route::put('/vessel-arrivals/{id}', [VesselArrivalController::class, 'update'])->whereUuid('id');
        Route::post('/vessel-arrivals/{id}/crew/check-in', [VesselArrivalController::class, 'checkInCrew'])->whereUuid('id');
        Route::post('/vessel-arrivals/{id}/crew/check-out', [VesselArrivalController::class, 'checkOutCrew'])->whereUuid('id');
        Route::post('/vessel-arrivals/{id}/crew/detach', [VesselArrivalController::class, 'detachCrew'])->whereUuid('id');
        Route::post('/vessel-arrivals/{id}/crew/reactivate', [VesselArrivalController::class, 'reactivateCrew'])->whereUuid('id');
        Route::post('/vessel-arrivals/{id}/crew/transfer', [VesselArrivalController::class, 'transferCrew'])->whereUuid('id');
        Route::delete('/vessel-arrivals/{id}', [VesselArrivalController::class, 'destroy'])->whereUuid('id');
    });

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

    // --- Pemeriksaan SMTP -------------------------------------------------
    // Mengirim SINKRON, bukan lewat antrean: sebuah uji yang mengantre menjawab
    // 200 walaupun worker-nya mati dan tidak ada surat yang pernah terkirim.
    Route::post('/admin/mail-test', MailTestController::class)
        ->middleware(['permission:manage_users', 'throttle:5,1']);
});
