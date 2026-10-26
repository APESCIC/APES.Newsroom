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
    | Comma-separated. Newsletter sender emails must use one of these domains.
    |
    */
    'sender_domains' => explode(',', (string) env('MAILING_SENDER_DOMAINS', 'apesnews.org.uk')),
];
