<?php

return [
    'enabled' => env('FINANCIAL_LIBRARY_FINES_ENABLED', false),
    'client_id' => env('FINANCIAL_LIBRARY_FINES_CLIENT_ID'),
    'recipient_organization_id' => env('FINANCIAL_LIBRARY_FINES_RECIPIENT_ORGANIZATION_ID'),
    'recipient_wallet_id' => env('FINANCIAL_LIBRARY_FINES_RECIPIENT_WALLET_ID'),
];
