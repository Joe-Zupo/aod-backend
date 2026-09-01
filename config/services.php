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

    'assemblyai' => [
        'api_key' => env('ASSEMBLYAI_API_KEY'),
        'base_url' => env('ASSEMBLYAI_BASE_URL', 'https://api.assemblyai.com'),
        // Reserved for a public callback URL. None in dev, so the jobs poll.
        'webhook_url' => env('ASSEMBLYAI_WEBHOOK_URL'),
        'speech_model' => env('ASSEMBLYAI_SPEECH_MODEL', 'best'),
        // Only these detected languages are accepted; anything else fails the
        // transcript loudly rather than storing a wrong-language result.
        'accepted_languages' => ['en', 'tl'],
        'poll_interval_seconds' => (int) env('ASSEMBLYAI_POLL_INTERVAL_SECONDS', 15),
        'max_polls' => (int) env('ASSEMBLYAI_MAX_POLLS', 40),
        'job_tries' => (int) env('ASSEMBLYAI_JOB_TRIES', 3),
    ],

];
