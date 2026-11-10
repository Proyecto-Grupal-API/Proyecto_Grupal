<?php

$convocatorias = json_decode((string) env('FINANCIAL_COMMUNITY_BECA_BUDGETS_JSON', '{}'), true);

return [
    'enabled' => env('COMMUNITY_BECA_FINANCE_ENABLED', false),
    'convocatorias' => is_array($convocatorias) ? $convocatorias : [],
    'e2' => [
        'url' => env('COMMUNITY_BECA_API_URL', 'http://app'),
        'client_id' => env('FINANCIAL_COMMUNITY_BECA_CLIENT_ID'),
        'client_secret' => env('FINANCIAL_COMMUNITY_BECA_CLIENT_SECRET'),
    ],
    'e6' => [
        'url' => env('FINANCIAL_BECA_API_URL', 'http://app'),
        'client_id' => env('COMMUNITY_FINANCIAL_BECA_CLIENT_ID'),
        'client_secret' => env('COMMUNITY_FINANCIAL_BECA_CLIENT_SECRET'),
    ],
];
