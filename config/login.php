<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Login
    |--------------------------------------------------------------------------
    |
    | Users log in through the OpenID Connect provider (e.g. Pocket ID). A
    | password login exists only for admins in the Filament panel
    | (/admin/login), as emergency access while the provider is down
    | (`php artisan accounts:make-admin <email> --password`).
    |
    */

    'oidc' => [
        'enabled' => (bool) env('AUTH_OIDC_ENABLED', false),
        'label' => env('AUTH_OIDC_LABEL', 'SSO'),

        // Create a local user on the first login of a provider user unknown here.
        'registration' => (bool) env('AUTH_OIDC_REGISTRATION', true),

        // Only users with this value in the "groups" claim may log in (usually empty).
        'required_group' => env('AUTH_OIDC_REQUIRED_GROUP') ?: null,
    ],

];
