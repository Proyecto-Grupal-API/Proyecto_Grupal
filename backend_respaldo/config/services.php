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

    // Integración Equipo 1 — Identidad, Acceso, NFC/QR y Perfil del Estudiante
    'team1' => [
        'base_url'      => env('TEAM1_BASE_URL', 'http://localhost:8001'),
        'shared_secret' => env('TEAM1_SERVICE_SECRET', 'secreto_equipo1_eq7_2026'),
        'service_token' => env('TEAM1_SERVICE_TOKEN', env('TEAM1_SERVICE_SECRET', 'secreto_equipo1_eq7_2026')),
        'timeout'       => (int) env('TEAM1_TIMEOUT', 5),
    ],

    // Integración Equipo 4 — Inventarios, Almacenes y Abastecimiento
    'team4' => [
        'base_url'      => env('TEAM4_BASE_URL', 'http://localhost:8004'),
        'shared_secret' => env('TEAM4_SERVICE_SECRET'),
        'business_id'   => env('TEAM4_BUSINESS_ID', 'BUS-CD-SOUV-001'),
        'timeout'       => (int) env('TEAM4_TIMEOUT', 5),
        'driver'        => env('INVENTORY_DRIVER', 'team4'), // 'team4' o 'none'
    ],

];
