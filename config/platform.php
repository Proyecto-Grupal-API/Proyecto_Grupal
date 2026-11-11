<?php

return [
    'notices' => [
        'enabled' => env('PLATFORM_COMMUNITY_NOTICES_ENABLED', false),
        'url' => env('PLATFORM_COMMUNITY_NOTICES_URL', 'http://app'),
        'client_id' => env('PLATFORM_COMMUNITY_NOTICES_CLIENT_ID'),
        'client_secret' => env('PLATFORM_COMMUNITY_NOTICES_CLIENT_SECRET'),
        'since' => env('PLATFORM_COMMUNITY_NOTICES_SINCE'),
    ],
    'community_analytics' => [
        'enabled' => env('PLATFORM_COMMUNITY_ANALYTICS_ENABLED', false),
        'since' => env('PLATFORM_COMMUNITY_ANALYTICS_SINCE'),
    ],
    'database' => env('PLATFORM_DATABASE'),
    // E1 remains the authority; only effective global roles enter the module.
    'role_map' => ['admin' => 'admin', 'maestro' => 'professor', 'estudiante' => 'student'],
    'delivery' => ['enabled' => env('PLATFORM_DELIVERY_ENABLED', false), 'url' => env('PLATFORM_API_URL', 'http://app')],
    'services_delivery' => [
        'enabled' => env('PLATFORM_SERVICES_DELIVERY_ENABLED', false),
        'url' => env('PLATFORM_SERVICES_API_URL', 'http://app'),
        'since' => env('PLATFORM_SERVICES_DELIVERY_SINCE'),
    ],
    'ingest' => ['enabled' => env('PLATFORM_INGEST_ENABLED', false), 'secrets' => [
        'community' => env('PLATFORM_COMMUNITY_SECRET'), 'inventory' => env('PLATFORM_INVENTORY_SECRET'),
        'services' => env('PLATFORM_SERVICES_SECRET'), 'wallet' => env('PLATFORM_WALLET_SECRET'),
    ]],
];
