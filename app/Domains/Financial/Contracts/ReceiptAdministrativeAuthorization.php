<?php

namespace App\Domains\Financial\Contracts;

interface ReceiptAdministrativeAuthorization
{
    /** Trusted session identity. Check live capability/context/revocation on every call.
     * Denial: AccessDeniedHttpException; unavailable: FinancialDependencyUnavailableException.
     * null is an unscoped operation, not a wildcard. Scope must exist in trusted directory.
     */
    public function assertRead(string $userId, ?array $scope): void;
}
