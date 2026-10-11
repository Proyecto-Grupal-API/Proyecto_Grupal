<?php
return [
    // A trusted integration replaces this binding. Never fall back on failure.
    'delegation_provider' => App\Domains\Financial\Adapters\PendingReceiptDelegationProvider::class,
];
