<?php

namespace App\Domains\Financial\Contracts;

interface FinancialWebAuthorizer
{
    /** Human authorization; wallet context is not a service OAuth scope. */
    public function allows(string $userId, string $action, ?string $walletId = null): bool;
}
