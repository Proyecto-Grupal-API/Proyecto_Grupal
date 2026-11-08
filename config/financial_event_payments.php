<?php

$recipients = json_decode((string) env('FINANCIAL_EVENT_RECIPIENTS_JSON', '{}'), true);

return [
    'enabled' => env('FINANCIAL_EVENT_PAYMENTS_ENABLED', false),
    'client_id' => env('FINANCIAL_EVENT_PAYMENTS_CLIENT_ID'),
    'recipients' => is_array($recipients) ? $recipients : [],
];
