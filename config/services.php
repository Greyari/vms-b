<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Master Data integration (Fase 4): visitor auto-fill + blacklist gate.
    // Disabled = VMS behaves exactly as before.
    'master_data' => [
        'enabled' => (bool) env('MASTER_DATA_ENABLED', false),
        'strict' => (bool) env('MASTER_DATA_BLACKLIST_STRICT', false),
        'base_url' => env('MASTER_DATA_BASE_URL', 'http://localhost:8001/api/v1'),
        'email' => env('MASTER_DATA_SVC_EMAIL', 'svc-vms@asl.local'),
        'password' => env('MASTER_DATA_SVC_PASSWORD'),
    ],

];
