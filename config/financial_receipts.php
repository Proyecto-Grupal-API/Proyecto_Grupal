<?php

use App\Domains\Financial\Adapters\PendingReceiptDelegationProvider;

return [
    // Admission candidate verified in development; this does NOT approve a review.
    'legacy_candidates' => [
        '91a56883-e21e-4df6-bb33-8533a8aba20d' => [
            'wallet_id' => '51e00662-42b2-4a2e-84d0-a5751b8d9a44',
            'reference_id' => '50cbf60a-b4af-4f3e-bbfa-972edb736462', 'amount_cents' => 10000,
        ],
    ],
    'administration_provider' => 'pending', // Await the approved Identity contract.
    // A trusted integration replaces this binding. Never fall back on failure.
    'delegation_provider' => env('FINANCIAL_RECEIPT_DELEGATION_PROVIDER', PendingReceiptDelegationProvider::class),
    'delegation_ttl_seconds' => (int) env('FINANCIAL_RECEIPT_DELEGATION_TTL_SECONDS', 600),
];
