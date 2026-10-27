<?php

namespace App\Domains\Financial\Adapters;

use App\Domains\Financial\Contracts\LegacyCashReceiptAuthorization;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;

class PendingLegacyCashReceiptAuthorization implements LegacyCashReceiptAuthorization
{
    public function assertGlobal(string $userId): void
    {
        throw new FinancialDependencyUnavailableException('La autorización de revisión histórica no está integrada.');
    }
}
