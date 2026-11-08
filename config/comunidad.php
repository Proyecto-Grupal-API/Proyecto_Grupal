<?php

return [
    'eventos_pagos' => [
        'enabled' => env('COMUNIDAD_EVENTOS_PAGOS_ENABLED', false),
        'url' => env('COMUNIDAD_FINANZAS_URL', 'http://app'),
        'client_id' => env('COMUNIDAD_FINANZAS_CLIENT_ID'),
        'client_secret' => env('COMUNIDAD_FINANZAS_CLIENT_SECRET'),
    ],
    'eventos_nfc_habilitado' => env('COMUNIDAD_EVENTOS_NFC_HABILITADO', false),
    'eventos_timezone' => 'America/Mexico_City',
    'eventos_lock_store' => 'file',
    'checkin_minutos_antes' => 30,
];
