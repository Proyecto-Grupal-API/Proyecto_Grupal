<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Negocio por defecto del Equipo 4
    |--------------------------------------------------------------------------
    | Los usuarios con scope "business" usan su role_business_id asignado por
    | Eq. 1. Los usuarios globales y los procesos de integracion usan este
    | negocio por defecto.
    */
    'default_business_id' => env('TEAM4_BUSINESS_ID', 'BUS-CD-SOUV-001'),
    'business_id' => env('TEAM4_BUSINESS_ID', 'BUS-CD-SOUV-001'),
    'alert_recipient_email' => env('TEAM4_ALERT_EMAIL'),
    'inactive_product_days' => (int) env('TEAM4_INACTIVE_PRODUCT_DAYS', 90),
];