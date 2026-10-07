<?php

use Laravel\Fortify\Features;

return [

    /*
    |--------------------------------------------------------------------------
    | Fortify Guard
    |--------------------------------------------------------------------------
    |
    | The authentication guard Fortify's routes and actions operate under.
    |
    */

    'guard' => 'web',

    /*
    |--------------------------------------------------------------------------
    | Fortify Routes Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Fortify Authenticated Middleware
    |--------------------------------------------------------------------------
    */

    'auth_middleware' => 'auth',

    /*
    |--------------------------------------------------------------------------
    | Password Broker
    |--------------------------------------------------------------------------
    */

    'passwords' => 'users',

    /*
    |--------------------------------------------------------------------------
    | Username / Email
    |--------------------------------------------------------------------------
    */

    'username' => 'email',
    'email' => 'email',

    /*
    |--------------------------------------------------------------------------
    | Headless mode — no Fortify views
    |--------------------------------------------------------------------------
    |
    | Fortify is used as a headless auth backend only (decision 001-D01).
    | No Fortify view responses are registered here: the custom Blade views
    | for the auth screens ship in a later ticket, after the UI mockup is
    | approved (decision 001-D06). The POST/PUT/DELETE auth endpoints
    | (login, registration, password reset, password confirmation, 2FA
    | challenge) remain registered.
    |
    */

    'views' => false,

    'home' => '/home',
    'prefix' => '',
    'domain' => null,
    'lowercase_usernames' => false,

    /*
    |--------------------------------------------------------------------------
    | Rate Limiters
    |--------------------------------------------------------------------------
    |
    | Null defers to Fortify's defaults. Brute-force lockout with backoff
    | (C-03) is implemented in a later ticket, not via these limiters.
    |
    */

    'limiters' => [
        'login' => null,
        'two-factor' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Paths
    |--------------------------------------------------------------------------
    |
    | Null defers to Fortify's default paths.
    |
    */

    'paths' => [
        'login' => null,
        'logout' => null,
        'password' => [
            'request' => null,
            'reset' => null,
            'email' => null,
            'update' => null,
            'confirm' => null,
            'confirmation' => null,
        ],
        'register' => null,
        'verification' => [
            'notice' => null,
            'verify' => null,
            'send' => null,
        ],
        'user-profile-information' => [
            'update' => null,
        ],
        'user-password' => [
            'update' => null,
        ],
        'two-factor' => [
            'login' => null,
            'enable' => null,
            'confirm' => null,
            'disable' => null,
            'qr-code' => null,
            'secret-key' => null,
            'recovery-codes' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Redirects
    |--------------------------------------------------------------------------
    |
    | Spec 003 decision 003-D02 [P]: until the app home ships in its own
    | spec, logins and logouts land on the public landing page ('/'), and
    | registrations land on the sign-in page (the verification toast is
    | flashed by the RegisterResponse binding in AppServiceProvider).
    |
    */

    'redirects' => [
        'login' => '/',
        'logout' => '/',
        'password-confirmation' => null,
        'register' => '/login',
        'email-verification' => null,
        'password-reset' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | The auth features enabled for this application. Passkeys are out of
    | scope for the foundation feature (SSO is a separate spec, 001-D05).
    |
    | Password confirmation needs no feature flag: Fortify registers the
    | confirm-password endpoints unconditionally (only the GET view is
    | gated by views above), so it is on in headless mode by default.
    |
    */

    'features' => array_values(array_filter([
        // Decision 001-D09: self-registration is allowed by default. Disable
        // it per-environment with FORTIFY_REGISTRATION=false.
        filter_var(env('FORTIFY_REGISTRATION', true), FILTER_VALIDATE_BOOLEAN)
            ? Features::registration()
            : null,
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true, // C-06: 2FA enrollment confirmed by a valid code.
            'confirmPassword' => true, // C-06: disabling 2FA requires password re-auth.
        ]),
    ])),

];
