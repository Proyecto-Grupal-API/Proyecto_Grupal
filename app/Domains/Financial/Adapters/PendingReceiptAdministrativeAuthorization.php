<?php
namespace App\Domains\Financial\Adapters;
use App\Domains\Financial\Contracts\ReceiptAdministrativeAuthorization;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
class PendingReceiptAdministrativeAuthorization implements ReceiptAdministrativeAuthorization
{
    public function assertRead(string $userId, ?array $scope): void {
        throw new FinancialDependencyUnavailableException('La autorización administrativa de comprobantes no está integrada.');
    }
}
