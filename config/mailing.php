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

    /*
    |--------------------------------------------------------------------------
    | Newsletter transport
    |--------------------------------------------------------------------------
    |
    | "mailgun" sends newsletters and sending-domain test emails through the
    | Mailgun HTTP API, using each From domain's own Mailgun domain, whenever
    | the Mailgun API key is configured. "smtp" keeps newsletters on the
    | default mailer (Cloudron SMTP). System and account mail always use the
    | default mailer.
    |
    */
    'newsletter_transport' => env('MAILING_NEWSLETTER_TRANSPORT', 'mailgun'),

    /*
    |--------------------------------------------------------------------------
    | Sending-domain test emails
    |--------------------------------------------------------------------------
    */
    'test_send' => [
        'per_user_per_minute' => (int) env('MAILING_TEST_SEND_PER_MINUTE', 5),
        'per_domain_per_day' => (int) env('MAILING_TEST_SEND_PER_DOMAIN_PER_DAY', 20),
    ],
];
