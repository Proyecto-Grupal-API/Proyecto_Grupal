<?php

return [
    'database' => env('MARKETPLACE_DATABASE'),
    'identidad' => ['url' => env('MARKETPLACE_IDENTIDAD_URL', 'http://app'), 'client_id' => env('MARKETPLACE_IDENTIDAD_CLIENT_ID'), 'client_secret' => env('MARKETPLACE_IDENTIDAD_CLIENT_SECRET')],
    'comunidad' => ['url' => env('MARKETPLACE_COMUNIDAD_URL', 'http://app'), 'client_id' => env('MARKETPLACE_COMUNIDAD_CLIENT_ID'), 'client_secret' => env('MARKETPLACE_COMUNIDAD_CLIENT_SECRET')],
    'inventory' => ['url' => env('MARKETPLACE_INVENTARIO_URL', 'http://app'), 'secret' => env('INVENTARIO_EQ3_SECRETO')],
    'hold_minutes' => 15,
];
