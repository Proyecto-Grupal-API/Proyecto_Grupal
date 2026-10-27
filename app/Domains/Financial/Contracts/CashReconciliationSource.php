<?php

namespace App\Domains\Financial\Contracts;

use App\Domains\Financial\Data\CashDifference;
use Carbon\CarbonImmutable;

/**
 * Contrato para comparar contra caja (submódulo 2.8).
 *
 * La conciliación solo diagnostica: una diferencia de caja nunca dispara
 * ajustes. Cada diferencia debe enlazarse con referencias autorizadas
 * por 2.8 (p. ej. el turno de caja). Mientras 2.8 no esté integrado en
 * el corte, el adaptador por defecto informa isIntegrated() = false.
 */
interface CashReconciliationSource
{
    public function isIntegrated(): bool;

    /**
     * @param  array<int, string>|null  $walletIds  null = todo el módulo
     * @return iterable<int, CashDifference>
     */
    public function differences(
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        ?array $walletIds
    ): iterable;
}
