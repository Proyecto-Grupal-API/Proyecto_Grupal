<?php

return [
    'database' => env('MARKETPLACE_DATABASE'),
    // Contexto `service` de Identidad cuyo rol `admin` acredita la revisión institucional de Tienda (además del administrador global).
    'reviewer_context' => 'tienda',
    'identidad' => ['url' => env('MARKETPLACE_IDENTIDAD_URL', 'http://app'), 'client_id' => env('MARKETPLACE_IDENTIDAD_CLIENT_ID'), 'client_secret' => env('MARKETPLACE_IDENTIDAD_CLIENT_SECRET')],
    'comunidad' => ['url' => env('MARKETPLACE_COMUNIDAD_URL', 'http://app'), 'client_id' => env('MARKETPLACE_COMUNIDAD_CLIENT_ID'), 'client_secret' => env('MARKETPLACE_COMUNIDAD_CLIENT_SECRET')],
    'inventory' => ['url' => env('MARKETPLACE_INVENTARIO_URL', 'http://app'), 'secret' => env('INVENTARIO_EQ3_SECRETO')],
    'wallet_checkout' => [
        'enabled' => env('MARKETPLACE_WALLET_CHECKOUT_ENABLED', false),
        'pilot_buyer_id' => env('MARKETPLACE_WALLET_PILOT_BUYER_ID'),
        'pilot_business_id' => env('MARKETPLACE_WALLET_PILOT_BUSINESS_ID'),
        'url' => env('MARKETPLACE_FINANZAS_URL', 'http://app'),
        'client_id' => env('MARKETPLACE_FINANZAS_CLIENT_ID'),
        'client_secret' => env('MARKETPLACE_FINANZAS_CLIENT_SECRET'),
    ],
    // Avisos de ventas confirmadas y devoluciones aprobadas hacia Recompensas (E7). Apagado no encola ni envía nada.
    'rewards' => ['enabled' => env('MARKETPLACE_REWARDS_ENABLED', false), 'url' => env('MARKETPLACE_REWARDS_URL', 'http://app')],
    'hold_minutes' => 15,
];
