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

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),

        /*
         * How hard the model works before answering. Drafting a post from a
         * title is not a reasoning-heavy job, so the cheapest, fastest setting
         * is the default; raise it if drafts come back thin.
         */
        'effort' => env('ANTHROPIC_EFFORT', 'low'),

        /*
         * Seconds to wait for a draft. Generous by HTTP standards because the
         * model writes the whole post before it answers.
         */
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 60),
    ],

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

];
