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

    // "Create account" without a service (/register); needs a managed identity provider.
    'signup' => (bool) env('AUTH_SIGNUP_ENABLED', true),

    'oidc' => [
        'enabled' => (bool) env('AUTH_OIDC_ENABLED', false),
        // Name on the buttons; empty: the APP_NAME of a Pocket ID provider, otherwise "SSO" (App\Auth\OidcLabel).
        'label' => env('AUTH_OIDC_LABEL') ?: null,

        // Create a local user on the first login of a provider user unknown here.
        'registration' => (bool) env('AUTH_OIDC_REGISTRATION', true),

        // Only users with this value in the "groups" claim may log in (usually empty).
        'required_group' => env('AUTH_OIDC_REQUIRED_GROUP') ?: null,
    ],

];
