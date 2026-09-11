<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Published to add `broadcasting/auth` to the framework default of
    | ['api/*', 'sanctum/csrf-cookie']. Echo posts a channel-authorization
    | request there for every private channel; without it, the SPA's lobby
    | and timeline subscriptions fail at the preflight with no visible error.
    |
    | This is a token-based API (see bootstrap/app.php), so the browser sends
    | a bearer header rather than cookies and `supports_credentials` stays
    | false. Origins are pinned to the SPA rather than '*' — a wildcard is
    | incompatible with credentialed requests if that ever changes.
    |
    */

    'paths' => ['api/*', 'broadcasting/auth', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:5173'),
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
