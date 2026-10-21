<?php
$clients = json_decode((string) env('FINANCIAL_MARKETPLACE_CLIENTS_JSON', '{}'), true);
return [
    'enabled' => (bool) env('FINANCIAL_MARKETPLACE_ENABLED', false),
    'clients' => is_array($clients) ? $clients : [],
    'ttl_seconds' => (int) env('FINANCIAL_MARKETPLACE_TTL_SECONDS', 600),
    'password_max_age_seconds' => (int) env('FINANCIAL_MARKETPLACE_PASSWORD_MAX_AGE_SECONDS', 300),
];
