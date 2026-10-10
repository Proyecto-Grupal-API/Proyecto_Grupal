<?php
namespace App\Domains\Financial\Contracts;
interface TransferAccountProvider
{
    /** Reject inactive/missing accounts; dependency outages must fail closed with 503. */
    public function assertActive(string $userId): void;
}
