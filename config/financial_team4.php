<?php
return [
    'enabled' => (bool) env('FINANCIAL_TEAM4_WEBHOOK_ENABLED', false),
    'url' => env('FINANCIAL_TEAM4_WEBHOOK_URL', ''),
    'secret' => env('FINANCIAL_TEAM4_WEBHOOK_SECRET', ''),
];
