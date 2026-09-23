<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudron LDAP addon
    |--------------------------------------------------------------------------
    |
    | Read directly from the CLOUDRON_LDAP_* environment variables Cloudron
    | injects for the LDAP addon (docs.cloudron.io/packaging/addons#ldap).
    | Consumed by staff role reconciliation via LdapGroupLookup.
    | Values are absent outside Cloudron; group mapping happens in code,
    | not here, so no group names are hard-coded to a config value.
    |
    */

    'cloudron_ldap' => [
        'url' => env('CLOUDRON_LDAP_URL'),
        'users_base_dn' => env('CLOUDRON_LDAP_USERS_BASE_DN'),
        'groups_base_dn' => env('CLOUDRON_LDAP_GROUPS_BASE_DN'),
        'bind_dn' => env('CLOUDRON_LDAP_BIND_DN'),
        'bind_password' => env('CLOUDRON_LDAP_BIND_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudron OIDC addon
    |--------------------------------------------------------------------------
    |
    | Read from the CLOUDRON_OIDC_* environment variables Cloudron injects
    | for the manually-registered OIDC client (docs.cloudron.io/packaging/
    | addons#oidc). On LAMP apps, create the client in the Cloudron
    | dashboard and set these in /app/data/shared/.env — see
    | docs/deployment.md.
    |
    */

    'cloudron_oidc' => [
        'discovery_url' => env('CLOUDRON_OIDC_DISCOVERY_URL'),
        'issuer' => env('CLOUDRON_OIDC_ISSUER'),
        'client_id' => env('CLOUDRON_OIDC_CLIENT_ID'),
        'client_secret' => env('CLOUDRON_OIDC_CLIENT_SECRET'),
        'provider_name' => env('CLOUDRON_OIDC_PROVIDER_NAME', 'Cloudron'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe (membership billing — test mode only for v1.4.0)
    |--------------------------------------------------------------------------
    |
    | Live mode keys and real charges require separate operational sign-off.
    | Leave STRIPE_SECRET empty locally/CI to use the fake billing client.
    |
    */

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'publishable' => env('STRIPE_PUBLISHABLE'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'price_monthly' => env('STRIPE_PRICE_MONTHLY'),
        'price_yearly' => env('STRIPE_PRICE_YEARLY'),
    ],

];
