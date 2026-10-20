<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter(array_map('trim', explode(',', env('FRONTEND_URL', 'http://localhost:5173')))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'Authorization', 'X-Requested-With', 'Idempotency-Key'],

    'exposed_headers' => [],

    'max_age' => 3600,

    // Autenticación por token Bearer: no se necesitan cookies cross-site.
    'supports_credentials' => false,
];
