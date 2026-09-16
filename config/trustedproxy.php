<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Proxies whose X-Forwarded-* headers are believed. Laravel's TrustProxies
    | middleware reads this whenever bootstrap/app.php does not set proxies.
    |
    | Local Windows server on the LAN: leave TRUSTED_PROXIES empty. Apache talks
    | to the counter PCs directly, so any forwarded header is made up by the
    | client, and believing it lets anyone forge the IP in the activity log and
    | slip past rate limits.
    |
    | Azure: set TRUSTED_PROXIES=* in App Settings. The App Service hostname only
    | accepts traffic through the Static Web Apps linked backend, so every
    | request really does arrive through that proxy.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
