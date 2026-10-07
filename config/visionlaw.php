<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Session policies (C-07)
    |--------------------------------------------------------------------------
    |
    | session_limit: maximum concurrent sessions per user. When a new login
    | would exceed the limit, the oldest sessions are kicked.
    |
    | session_absolute_lifetime_hours: absolute max session age for
    | privileged roles (org_admin + attorney — the contract names no roles,
    | so this default is recorded here; see decisions.md 001-D14 note in
    | the T-05 report). Enforced by EnsureSessionLifetime middleware.
    */

    'session_limit' => (int) env('VISIONLAW_SESSION_LIMIT', 5),

    'session_absolute_lifetime_hours' => (int) env('VISIONLAW_SESSION_LIFETIME_HOURS', 12),

    'privileged_roles' => ['org_admin', 'attorney'],

    /*
    |--------------------------------------------------------------------------
    | Invitations (C-05)
    |--------------------------------------------------------------------------
    */

    'invitation_ttl_days' => (int) env('VISIONLAW_INVITATION_TTL_DAYS', 7),
];
