<?php

namespace App\Domains\Financial\Adapters;

use App\Domains\Financial\Contracts\FinancialWebAuthorizer;

class PendingFinancialWebAuthorizer implements FinancialWebAuthorizer
{
    public function allows(string $userId, string $action, ?string $walletId = null): bool
    {
        // Legacy self-declared roles are not proof of administrative authority.
        return false;
    }
}
