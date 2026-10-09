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

    /*
    | API de Servicios al Estudiante (Equipo 5). Clientes con token de
    | servicio para desarrollo/pruebas mientras no se use el OAuth del
    | Equipo 1. Nunca subir tokens reales al repositorio.
    */
    'student_services_api' => [
        'clients' => [
            [
                'id' => env('STUDENT_SERVICES_API_CLIENT_ID', 'equipo6-comunidad'),
                'token' => env('STUDENT_SERVICES_API_TOKEN'),
                'scopes' => env('STUDENT_SERVICES_API_SCOPES', 'services:benefits:read services:benefits:write'),
            ],
        ],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
