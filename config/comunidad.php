<?php

// Unset preserves existing deployments; an explicit empty/invalid list denies new payments.
$raw = env('COMUNIDAD_EVENTOS_PAGOS_ORGANIZACIONES');
$organizations = $raw === null ? null : json_decode((string) $raw, true);

return [
    'eventos_pagos' => [
        'enabled' => env('COMUNIDAD_EVENTOS_PAGOS_ENABLED', false),
        'organizaciones' => $raw !== null && ! is_array($organizations) ? [] : $organizations,
        'url' => env('COMUNIDAD_FINANZAS_URL', 'http://app'),
        'client_id' => env('COMUNIDAD_FINANZAS_CLIENT_ID'),
        'client_secret' => env('COMUNIDAD_FINANZAS_CLIENT_SECRET'),
    ],
    'avisos_finanzas_client_id' => env('FINANCIAL_COMMUNITY_NOTICES_CLIENT_ID'),
    'eventos_nfc_habilitado' => env('COMUNIDAD_EVENTOS_NFC_HABILITADO', false),
    'eventos_timezone' => 'America/Mexico_City',
    'eventos_lock_store' => 'file',
    'checkin_minutos_antes' => 30,
];
