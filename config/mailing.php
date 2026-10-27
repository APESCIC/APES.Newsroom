<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Campaign send throttle
    |--------------------------------------------------------------------------
    |
    | Maximum live campaign recipients accepted by SMTP per minute.
    | Measured capacity testing on beta may raise this later.
    |
    */
    'throttle_per_minute' => (int) env('MAILING_THROTTLE_PER_MINUTE', 60),

    'contact_address' => env('MAILING_CONTACT_ADDRESS', 'newsroom@apesnews.org.uk'),

    /*
    |--------------------------------------------------------------------------
    | Approved newsletter sender domains
    |--------------------------------------------------------------------------
    |
    | Comma-separated. Only used to seed Admin → Settings → Email sending
    | domains on first migration; after that the database list is the
    | source of truth.
    |
    */
    'sender_domains' => explode(',', (string) env('MAILING_SENDER_DOMAINS', 'apesnews.org.uk')),
];
