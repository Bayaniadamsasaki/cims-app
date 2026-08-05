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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'mikrotik' => [
        'host' => env('MIKROTIK_HOST', '192.168.88.1'),
        'user' => env('MIKROTIK_USER', 'admin'),
        'password' => env('MIKROTIK_PASSWORD', ''),
        'port' => (int) env('MIKROTIK_PORT', 8728),
        'ssl' => (bool) env('MIKROTIK_SSL', false),
        'timeout' => (int) env('MIKROTIK_TIMEOUT', 10),
        'attempts' => (int) env('MIKROTIK_ATTEMPTS', 2),
    ],

    'ruijie' => [
        'app_id' => env('RUIJIE_APP_ID', 'open2a30c702449b'),
        'secret' => env('RUIJIE_SECRET', '779af05e4ece46308add65013a8154c1'),
        'base_url' => env('RUIJIE_BASE_URL', 'https://cloud-as.ruijienetworks.com'),
        'timeout' => 15,
    ],

];
