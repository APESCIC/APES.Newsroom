<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Security data retention
    |--------------------------------------------------------------------------
    |
    | Enforced daily by `newsroom:purge-security-data`. Keep
    | docs/governance/retention-schedule.md in sync when changing these.
    |
    */

    'audit_retention_months' => (int) env('SECURITY_AUDIT_RETENTION_MONTHS', 24),

    'magic_link_token_retention_days' => (int) env('SECURITY_MAGIC_LINK_RETENTION_DAYS', 7),

    'session_record_retention_days' => (int) env('SECURITY_SESSION_RECORD_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Password policy
    |--------------------------------------------------------------------------
    |
    | Applied to registration, reset and password change through
    | Password::defaults(). The breached-password check sends only the first
    | five characters of a SHA-1 hash to the Pwned Passwords range API and
    | fails open if that service cannot be reached.
    |
    */

    'password' => [
        'min_length' => (int) env('SECURITY_PASSWORD_MIN_LENGTH', 12),
        'max_length' => (int) env('SECURITY_PASSWORD_MAX_LENGTH', 128),
        'check_breached' => (bool) env('SECURITY_PASSWORD_CHECK_BREACHED', env('APP_ENV') === 'production'),
        'breached_check_timeout' => (int) env('SECURITY_PASSWORD_BREACHED_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Re-authentication
    |--------------------------------------------------------------------------
    |
    | Sensitive actions (account deletion, email change, webhook and settings
    | changes, signing out other sessions) need a sign-in or identity
    | confirmation within this many seconds.
    |
    */

    'reauth_timeout' => (int) env('SECURITY_REAUTH_TIMEOUT', 900),

    'reauth_code_ttl_minutes' => (int) env('SECURITY_REAUTH_CODE_TTL', 10),

];
