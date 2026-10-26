<?php

return [
    'enabled' => (bool) env('FINANCIAL_IDENTITY_AUTHORIZATION_ENABLED', false),
    'origin' => env('FINANCIAL_IDENTITY_AUTHORIZATION_ORIGIN', ''),
    'client_id' => env('FINANCIAL_IDENTITY_AUTHORIZATION_CLIENT_ID', ''),
    'client_secret' => env('FINANCIAL_IDENTITY_AUTHORIZATION_CLIENT_SECRET', ''),
];
