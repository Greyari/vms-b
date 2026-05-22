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
     * CORS ditangani sepenuhnya oleh IIS via web.config <httpProtocol><customHeaders>.
     * Laravel tidak perlu menambahkan CORS header — jika keduanya aktif akan terjadi
     * duplicate header yang menyebabkan browser menolak response.
     *
     * Untuk local development: gunakan Vite proxy (lihat vite.config.ts)
     * sehingga request dari browser ke /api diteruskan ke localhost:8000
     * tanpa melibatkan CORS sama sekali.
     */
    'paths' => [],

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
