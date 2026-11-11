<?php

return [
    'enabled' => env('FINANCIAL_COMMUNITY_NOTICES_ENABLED', false),
    'url' => env('FINANCIAL_COMMUNITY_NOTICES_URL', 'http://app'),
    'client_id' => env('FINANCIAL_COMMUNITY_NOTICES_CLIENT_ID'),
    'client_secret' => env('FINANCIAL_COMMUNITY_NOTICES_CLIENT_SECRET'),
    // Required ISO 8601 instant, including timezone. Never defaults to all history.
    'since' => env('FINANCIAL_COMMUNITY_NOTICES_SINCE'),
];
