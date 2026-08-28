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
        'host' => env('MIKROTIK_HOST'),
        'user' => env('MIKROTIK_USER'),
        'password' => env('MIKROTIK_PASSWORD'),
        'port' => (int) env('MIKROTIK_PORT'),
        'ssl' => (bool) env('MIKROTIK_SSL'),
        'timeout' => (int) env('MIKROTIK_TIMEOUT', 10),
        'attempts' => (int) env('MIKROTIK_ATTEMPTS', 2),
    ],

    // Identitas hotspot kampus untuk dicetak di kartu voucher mahasiswa.
    // Semua nilai di blok ini hanya berasal dari .env — jangan tulis literal di
    // sini, di controller, atau di komponen React, supaya cukup satu tempat yang
    // diubah saat SSID / portal / router hotspot kampus berganti.
    'hotspot' => [
        'ssid' => env('HOTSPOT_SSID'),
        'login_url' => env('HOTSPOT_LOGIN_URL'),

        // Router yang benar-benar menjalankan /ip/hotspot. Sering berbeda dari
        // MIKROTIK_HOST (router monitoring/uplink), jadi voucher dipush ke sini.
        'router_host' => env('HOTSPOT_ROUTER_HOST'),

        // User profile RouterOS untuk voucher baru bila tidak dipilih di form.
        // Kosongkan bila ingin memakai profile "default" milik router.
        'default_profile' => env('HOTSPOT_DEFAULT_PROFILE'),
    ],

    'ruijie' => [
        'app_id' => env('RUIJIE_APP_ID'),
        'secret' => env('RUIJIE_SECRET'),
        'base_url' => env('RUIJIE_BASE_URL'),
        'timeout' => 15,
    ],

];
