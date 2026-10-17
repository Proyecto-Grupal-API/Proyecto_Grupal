<?php
return [
    'business_id' => env('TEAM4_BUSINESS_ID', 'BUS-CD-SOUV-001'),
    'database' => env('INVENTARIO_DATABASE'),
    'identidad' => ['url' => env('INVENTARIO_IDENTIDAD_API_URL', 'http://app'),
        'client_id' => env('INVENTARIO_IDENTIDAD_CLIENT_ID'), 'client_secret' => env('INVENTARIO_IDENTIDAD_CLIENT_SECRET')],
    'role_map_business' => ['business_owner' => 'admin', 'business_manager' => 'business_manager',
        'inventory_manager' => 'inventory_manager', 'buyer' => 'buyer'],
    'outbox' => ['enabled' => env('INVENTARIO_E7_OUTBOX_HABILITADO', false),
        'url' => env('TEAM7_WEBHOOK_URL'), 'token' => env('INVENTARIO_E7_TOKEN')],
    'integration' => [
        'business_id' => env('TEAM4_API_BUSINESS_ID', env('TEAM4_BUSINESS_ID', 'BUS-CD-SOUV-001')),
        'allowed_clock_skew' => 300,
        'secrets' => ['equipo3' => env('INVENTARIO_EQ3_SECRETO'), 'equipo2' => env('INVENTARIO_EQ2_SECRETO'), 'equipo7' => env('INVENTARIO_EQ7_SECRETO')],
        'payment_events_enabled' => env('INVENTARIO_PAGOS_HABILITADOS', false),
    ],
];
