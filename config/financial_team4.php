<?php

return [
    'enabled' => (bool) env('FINANCIAL_TEAM4_WEBHOOK_ENABLED', false),
    'pilot_client_id' => env('FINANCIAL_TEAM4_PILOT_CLIENT_ID'),
    'url' => env('FINANCIAL_TEAM4_WEBHOOK_URL', ''),
    'secret' => env('FINANCIAL_TEAM4_WEBHOOK_SECRET', ''),
];
