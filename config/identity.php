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

    // Page of the provider where users manage their passkeys (linked in the portal and the profile).
    'account_url' => env('IDENTITY_ACCOUNT_URL') ?: (env('IDENTITY_DRIVER') === 'pocket-id' && env('AUTH_OIDC_ISSUER')
        ? rtrim((string) env('AUTH_OIDC_ISSUER'), '/').'/settings/account'
        : null),

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
