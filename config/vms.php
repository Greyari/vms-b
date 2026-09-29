<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Visitor / blacklist import row cap
    |--------------------------------------------------------------------------
    |
    | Hard ceiling on the number of data rows a single CSV/XLSX import may
    | contain. The importer aborts with a 422 before touching the database
    | when a file exceeds this, so a huge or malformed upload can't lock the
    | visitors table inside one long transaction.
    |
    */

    'import_max_rows' => 2000,

    /*
    |--------------------------------------------------------------------------
    | Visitor number floor
    |--------------------------------------------------------------------------
    |
    | Lowest visitor_no the application assigns. Numbers below it are reserved
    | for visitors imported from VMS-old, whose VisitorID is kept as visitor_no
    | (currently at most 347,414). Keeping new numbers above the floor means a
    | delta import on cutover night can never collide with a visitor the new
    | system created in the meantime.
    |
    */

    'visitor_no_floor' => 1_000_000,

    /*
    |--------------------------------------------------------------------------
    | SPA index
    |--------------------------------------------------------------------------
    |
    | The React build's index.html. On the local Windows server the release
    | script copies the build into public/, and every non-API path is answered
    | with this file. Azure serves the SPA from Static Web Apps, so the file is
    | absent there and those paths answer 404.
    |
    */

    'spa_index' => env('VMS_SPA_INDEX', public_path('index.html')),

    /*
    |--------------------------------------------------------------------------
    | Yard branding (decision PE-7)
    |--------------------------------------------------------------------------
    |
    | One SPA build serves every yard; this server's own company name, address
    | and logo go on the printed pass and the reports. The logo is a file name
    | inside public/branding/ (copied there at install, never in the release).
    |
    */

    'branding' => [
        'company_name' => (string) env('VMS_COMPANY_NAME', ''),
        'company_address' => (string) env('VMS_COMPANY_ADDRESS', ''),
        'logo' => env('VMS_COMPANY_LOGO') ?: null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloud Hub sync (hybrid multi-yard, decision BE-6)
    |--------------------------------------------------------------------------
    |
    | `php artisan vms:sync-cloud-hub`, run every minute by Task Scheduler, does
    | nothing until VMS_SYNC_ENABLED is true. The gate never needs the hub: a
    | failed cycle is recorded in sync_state_logs and the counters keep working.
    | hub_url ends in /api/v1 (https://<static web app host>/api/v1). The HMAC
    | secret is this yard's only credential for the hub; it lives in .env only.
    |
    */

    'sync' => [
        'enabled' => (bool) env('VMS_SYNC_ENABLED', false),
        'yard_code' => strtoupper(trim((string) env('VMS_YARD_CODE', ''))),
        'hub_url' => rtrim((string) env('VMS_CLOUD_HUB_URL', ''), '/'),
        'key_id' => (string) env('VMS_CLOUD_HMAC_KEY_ID', ''),
        'secret' => (string) env('VMS_CLOUD_HMAC_SECRET', ''),
        'timeout_seconds' => 15,
        'pre_registration_batch' => 100,
        'blacklist_batch' => 500,
    ],

];
