<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Identity provider (admin API)
    |--------------------------------------------------------------------------
    |
    | "pocket-id": users and groups are created and synced through the Pocket ID
    | API. "manual": reduced mode, the admin manages users and groups in the
    | provider by hand (any OIDC provider; login, services, subscriptions and
    | households keep working).
    |
    */

    'driver' => env('IDENTITY_DRIVER', 'manual'),

    'pocket_id' => [
        // Base URL of the API as reached from this server (e.g. https://login.example.com).
        'url' => rtrim((string) env('POCKET_ID_URL', env('AUTH_OIDC_ISSUER', '')), '/'),
        // API key of an admin (Pocket ID: Administration → API Keys).
        'api_key' => env('POCKET_ID_API_KEY'),
        // Lifetime of the login code mailed after a registration (Go duration, e.g. 1h).
        'login_code_ttl' => env('POCKET_ID_LOGIN_CODE_TTL', '1h'),
        'timeout' => (int) env('POCKET_ID_TIMEOUT', 15),
    ],

];
