<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    /*
     * Laravel handles CORS for api/* directly (via this config + the
     * HandleCors middleware) — there is no IIS/Vite-proxy layer in front of
     * it. `allowed_origins` lists the browser origins allowed to call the API.
     *
     * Production topology (owner, 2026-07-18) — NOT a mismatch, two roles:
     *   Frontend  https://vms.cemaraintan.id    (cPanel shared hosting) — this is
     *             the browser ORIGIN that calls the API, so it is listed below.
     *   Backend   https://frs.aslmarine.com/vms  (Singapore VPS) — where this API
     *             is served; it is the frontend's API base URL, NOT a CORS origin.
     * Local dev uses the Vite dev server on 5173/5174.
     */
    'paths' => [
        'api/*',
    ],

    'allowed_methods' => ['*'],

    // 'allowed_origins' => ['*'],
    'allowed_origins' => [
        'http://localhost:5173',
        'http://localhost:5174',
        'http://localhost:8000',
        // Production frontend origin (cPanel). This is the host the browser
        // requests come FROM; the backend itself (frs.aslmarine.com) is not an
        // origin and does not belong here.
        'https://vms.cemaraintan.id',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
