<?php

namespace App\Domains\Financial\Adapters;

use App\Domains\Financial\Contracts\FinancialRoleProvider;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;

/**
 * Team 1 publishes business-scoped assignments, not a global role contract
 * for wallet owners. Never substitute historical User.roles or bypass a policy.
 */
class PendingFinancialRoleProvider implements FinancialRoleProvider
{
    public function rolesOf(string $ownerType, string $ownerId): array
    {
        throw new FinancialDependencyUnavailableException(
            'El contrato de roles financieros con el Módulo 1 está pendiente de integración.'
        );
    }
}
