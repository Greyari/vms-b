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
     * Production  → IIS web.config yang handle CORS via <httpProtocol><customHeaders>.
     * Local/Dev   → Laravel yang handle CORS (php artisan serve tidak pakai IIS).
     *
     * Ini mencegah duplicate CORS header (IIS + Laravel secara bersamaan)
     * yang menyebabkan browser menolak response.
     */
    'paths' => app()->environment('production') ? [] : ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // 'allowed_origins' => ['*'],
    'allowed_origins' => [
        'http://localhost:5173',
        'http://localhost:5174',
        'http://localhost:8000',
        'https://vms.cemaraintan.id',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
