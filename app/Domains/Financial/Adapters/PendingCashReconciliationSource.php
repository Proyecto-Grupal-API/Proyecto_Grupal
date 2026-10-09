<?php

namespace App\Domains\Financial\Adapters;

use App\Domains\Financial\Contracts\CashReconciliationSource;
use Carbon\CarbonImmutable;

/**
 * Adaptador por defecto mientras 2.8 (caja) no esté integrado en este
 * corte. No compara nada y lo declara explícitamente: la conciliación
 * registra cash_status = NO_INTEGRADA.
 */
class PendingCashReconciliationSource implements CashReconciliationSource
{
    public function isIntegrated(): bool
    {
        return false;
    }

    public function differences(
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        ?array $walletIds
    ): iterable {
        return [];
    }
}
