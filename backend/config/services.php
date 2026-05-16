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

];
