<?php
return [
    'timezone' => 'America/Mexico_City',
    'identidad' => ['url' => env('SERVICIOS_IDENTIDAD_API_URL', 'http://app'), 'client_id' => env('SERVICIOS_IDENTIDAD_CLIENT_ID'), 'client_secret' => env('SERVICIOS_IDENTIDAD_CLIENT_SECRET')],
    'beneficios' => ['url' => env('SERVICIOS_API_URL', 'http://app'), 'client_id' => env('COMUNIDAD_SERVICIOS_CLIENT_ID'), 'client_secret' => env('COMUNIDAD_SERVICIOS_CLIENT_SECRET')],
    'avisos' => ['url' => env('COMUNIDAD_API_URL', 'http://app'), 'client_id' => env('SERVICIOS_COMUNIDAD_CLIENT_ID'), 'client_secret' => env('SERVICIOS_COMUNIDAD_CLIENT_SECRET')],
    'finanzas_multas' => [
        'enabled' => env('SERVICIOS_FINANZAS_MULTAS_ENABLED', false),
        'url' => env('SERVICIOS_FINANZAS_API_URL', 'http://app'),
        'client_id' => env('SERVICIOS_FINANZAS_MULTAS_CLIENT_ID'),
        'client_secret' => env('SERVICIOS_FINANZAS_MULTAS_CLIENT_SECRET'),
    ],
];
