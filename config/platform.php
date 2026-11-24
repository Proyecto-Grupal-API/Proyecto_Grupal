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
    // E1 remains the authority. Global roles: the platform administrator is E7's `platform_admin`
    // (design §2 «Administrador de plataforma»: catálogos, configuraciones y supervisión).
    'role_map' => ['admin' => 'platform_admin', 'maestro' => 'professor', 'estudiante' => 'student'],
    // Contextual assignments published by E1 (módulo 1.3: rol + contexto `service`). A charge limited to this
    // module does not need a global administrator: `admin` en el servicio `recompensas` administra el programa,
    // en `plataforma` aprueba configuraciones y catálogos del módulo, y en `auditoria` sólo consulta.
    'context_role_map' => [
        'admin' => ['service' => ['recompensas' => 'admin', 'plataforma' => 'platform_admin', 'auditoria' => 'auditor']],
    ],
    'delivery' => ['enabled' => env('PLATFORM_DELIVERY_ENABLED', false), 'url' => env('PLATFORM_API_URL', 'http://app')],
    'services_delivery' => [
        'enabled' => env('PLATFORM_SERVICES_DELIVERY_ENABLED', false),
        'url' => env('PLATFORM_SERVICES_API_URL', 'http://app'),
        'since' => env('PLATFORM_SERVICES_DELIVERY_SINCE'),
        // Eventos analíticos del Equipo 5 (servicios:publicar-eventos). Bandera
        // propia: encender la auditoría (enabled) no inicia este envío.
        'analytics_enabled' => env('PLATFORM_SERVICES_ANALYTICS_ENABLED', false),
    ],
    'ingest' => ['enabled' => env('PLATFORM_INGEST_ENABLED', false), 'secrets' => [
        'community' => env('PLATFORM_COMMUNITY_SECRET'), 'inventory' => env('PLATFORM_INVENTORY_SECRET'),
        'services' => env('PLATFORM_SERVICES_SECRET'), 'wallet' => env('PLATFORM_WALLET_SECRET'),
        // E3 -> E7: ventas confirmadas y devoluciones (contratos «Acumular puntos por venta» y «Revertir puntos por devolución»).
        'marketplace' => env('PLATFORM_MARKETPLACE_SECRET'),
        // E1 -> E7 (IdentityPlatformEnvelope, EVENTS_TRANSPORT=platform): debe coincidir con EVENTS_PLATFORM_SECRET.
        'identity' => env('PLATFORM_IDENTITY_SECRET'),
    ]],
];
