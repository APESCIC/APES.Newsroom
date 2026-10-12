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

];
