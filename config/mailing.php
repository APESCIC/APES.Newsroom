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
    | The starting newsletter transport until a super admin chooses one under
    | Admin → Settings → Mail sending: "mailgun" (the Mailgun HTTP API, using
    | each From domain's own Mailgun domain, once its API key is set) or
    | "smtp" (the environment mailer, Cloudron SMTP on Cloudron).
    |
    */
    'newsletter_transport' => env('MAILING_NEWSLETTER_TRANSPORT', 'mailgun'),

    /*
    |--------------------------------------------------------------------------
    | Mailgun webhooks
    |--------------------------------------------------------------------------
    */
    'webhook_per_minute' => (int) env('MAILING_WEBHOOK_PER_MINUTE', 600),

    /*
    |--------------------------------------------------------------------------
    | Test emails (sending domains and Mail sending)
    |--------------------------------------------------------------------------
    */
    'test_send' => [
        'per_user_per_minute' => (int) env('MAILING_TEST_SEND_PER_MINUTE', 5),
        'per_domain_per_day' => (int) env('MAILING_TEST_SEND_PER_DOMAIN_PER_DAY', 20),
    ],
];
