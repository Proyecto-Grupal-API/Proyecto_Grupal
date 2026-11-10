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
    'finanzas_garantias' => [
        'url' => env('SERVICIOS_FINANZAS_API_URL', 'http://app'),
        'client_id' => env('SERVICIOS_FINANZAS_GARANTIAS_CLIENT_ID'),
        'pilot_hold_ttl_seconds' => (int) env('SERVICIOS_FINANZAS_GARANTIAS_PILOT_TTL_SECONDS', 0),
        'client_secret' => env('SERVICIOS_FINANZAS_GARANTIAS_CLIENT_SECRET'),
    ],
    // Pago de lockers e impresiones. Puede ser el mismo cliente OAuth de multas con más scopes.
    'finanzas_servicios' => [
        'enabled' => env('SERVICIOS_FINANZAS_SERVICIOS_ENABLED', false),
        'url' => env('SERVICIOS_FINANZAS_API_URL', 'http://app'),
        'client_id' => env('SERVICIOS_FINANZAS_SERVICIOS_CLIENT_ID', env('SERVICIOS_FINANZAS_MULTAS_CLIENT_ID')),
        'client_secret' => env('SERVICIOS_FINANZAS_SERVICIOS_CLIENT_SECRET', env('SERVICIOS_FINANZAS_MULTAS_CLIENT_SECRET')),
    ],
];
