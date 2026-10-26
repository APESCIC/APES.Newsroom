<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Releases still in beta
    |--------------------------------------------------------------------------
    |
    | While true, the Admin → Releases create form defaults to the Beta channel
    | and seeded/baseline Change Log Hub notes use Beta. Flip to false when
    | Newsroom leaves beta; historical release rows stay on Beta.
    |
    */

    'releases_in_beta' => (bool) env('NEWSROOM_RELEASES_IN_BETA', true),

    /*
    |--------------------------------------------------------------------------
    | Changelog entry directory
    |--------------------------------------------------------------------------
    |
    | Absolute path to changelog/releases JSON files. Null uses
    | base_path('changelog/releases'). Override in tests.
    |
    */

    'changelog_path' => env('NEWSROOM_CHANGELOG_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Editor.js trusted media uploads (#75)
    |--------------------------------------------------------------------------
    |
    | Staff-only multipart uploads to the public disk. Sizes are Laravel KB.
    |
    */

    'editor_uploads' => [
        'disk' => 'public',
        'path_prefix' => 'editor',
        'kinds' => [
            'image' => [
                'mimes' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
                'max_kb' => 5120,
            ],
            'file' => [
                'mimes' => ['pdf', 'zip', 'txt', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp', 'gif'],
                'max_kb' => 10240,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Hero image uploads (#532)
    |--------------------------------------------------------------------------
    |
    | Staff-only hero image uploads. Originals are re-encoded to drop EXIF and
    | camera metadata, then a queued job writes responsive WebP and JPEG
    | widths plus a 1200x630 social crop. max_kb is Laravel KB; max_pixels
    | caps decoded image size so a small file cannot exhaust memory.
    |
    */

    'hero_uploads' => [
        'disk' => 'public',
        'path_prefix' => 'media',
        'max_kb' => (int) env('NEWSROOM_HERO_UPLOAD_MAX_KB', 10240),
        'max_pixels' => (int) env('NEWSROOM_HERO_UPLOAD_MAX_PIXELS', 40_000_000),
        'widths' => [640, 1200, 2000],
        'social' => ['width' => 1200, 'height' => 630],
        'quality' => ['jpeg' => 82, 'webp' => 80, 'original' => 90],
        'rate_per_minute' => (int) env('NEWSROOM_HERO_UPLOAD_RATE_PER_MINUTE', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Content API (headless reads)
    |--------------------------------------------------------------------------
    */

    'content_api' => [
        'key' => env('NEWSROOM_CONTENT_API_KEY'),
        'rate_per_minute' => (int) env('NEWSROOM_CONTENT_API_RATE_PER_MINUTE', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin API (editorial writes)
    |--------------------------------------------------------------------------
    */

    'admin_api' => [
        'rate_per_minute' => (int) env('NEWSROOM_ADMIN_API_RATE_PER_MINUTE', 60),
    ],

    'unsplash' => [
        'access_key' => env('UNSPLASH_ACCESS_KEY'),
    ],

    'ai_assist' => [
        'enabled' => (bool) env('NEWSROOM_AI_ASSIST_ENABLED', false),
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('NEWSROOM_AI_ASSIST_MODEL', 'gpt-4o-mini'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third-party analytics (consent-gated)
    |--------------------------------------------------------------------------
    |
    | provider: none | plausible | gtm-stub
    | Scripts load only after the visitor accepts analytics cookies.
    | Native first-party ContentViewRecorder metrics are unaffected.
    |
    */

    'analytics' => [
        'provider' => env('NEWSROOM_ANALYTICS_PROVIDER', 'none'),
        'plausible_domain' => env('NEWSROOM_PLAUSIBLE_DOMAIN'),
        'gtm_container_id' => env('NEWSROOM_GTM_CONTAINER_ID'),
    ],

];
