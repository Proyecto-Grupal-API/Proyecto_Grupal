<?php

return [
    'issuer' => env('OAUTH2_ISSUER', env('APP_URL', 'http://localhost:8000')),
    'audience' => env('OAUTH2_AUDIENCE', 'campus-virtual-services'),
    'signing_key' => env('OAUTH2_SIGNING_KEY', env('APP_KEY')),
    'access_token_ttl' => (int) env('OAUTH2_ACCESS_TOKEN_TTL', 3600),
];
