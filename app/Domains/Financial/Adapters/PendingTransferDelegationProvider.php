<?php
namespace App\Domains\Financial\Adapters;
use App\Domains\Financial\Contracts\TransferDelegationProvider;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
class PendingTransferDelegationProvider implements TransferDelegationProvider
{
    public function resolve(string $clientId, string $proof, string $action, string $requestDigest): string
    {
        throw new FinancialDependencyUnavailableException('La delegación de identidad del módulo 1 todavía no está integrada.');
    }
}
