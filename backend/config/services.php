<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'google_oauth_enabled' => env('GOOGLE_OAUTH_ENABLED', false),

    'fal' => [
        'api_key' => env('FAL_API_KEY'),
        'base_url' => env('FAL_BASE_URL', 'https://queue.fal.run'),
        'model' => env('FAL_MODEL', 'fal-ai/flux-2/turbo'),
        'daily_global_limit' => (int) env('FAL_DAILY_GLOBAL_LIMIT', 200),
        'prompt_denylist' => [
            'nsfw', 'nude', 'naked', 'explicit', 'porn', 'xxx', 'sexual', 'blood', 'gore',
        ],
        'poll_interval_ms' => 1000,
        'poll_max_seconds' => 60,
    ],

    'github' => [
        'token' => env('GITHUB_TOKEN'),
        'base_url' => env('GITHUB_BASE_URL', 'https://api.github.com'),
        'user_agent' => env('GITHUB_USER_AGENT', 'Fishbook/1.0 (+https://fishbook.neri.ph)'),
        'cache_ttl_seconds' => (int) env('GITHUB_CACHE_TTL', 600),
        'lock_ttl_seconds' => (int) env('GITHUB_LOCK_TTL', 60),
        'lock_block_seconds' => (int) env('GITHUB_LOCK_BLOCK', 5),
        'cache_key_version' => 'v1',
        'language_colors' => [
            'JavaScript' => '#F7DF1E',
            'TypeScript' => '#3178C6',
            'Python' => '#3776AB',
            'Ruby' => '#CC342D',
            'Go' => '#00ADD8',
            'Rust' => '#DEA584',
            'PHP' => '#777BB4',
            'Java' => '#ED8B00',
            'C' => '#A8B9CC',
            'C++' => '#00599C',
            'Shell' => '#89E051',
        ],
    ],

];
