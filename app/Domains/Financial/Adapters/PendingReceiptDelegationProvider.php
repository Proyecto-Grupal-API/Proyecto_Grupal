<?php

namespace App\Domains\Financial\Adapters;

use App\Domains\Financial\Contracts\ReceiptDelegationProvider;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;

class PendingReceiptDelegationProvider implements ReceiptDelegationProvider
{
    public function resolve(string $clientId, string $proof, string $action, string $requestDigest): string
    {
        throw new FinancialDependencyUnavailableException('La delegación de consulta de comprobantes no está integrada.');
    }
}
