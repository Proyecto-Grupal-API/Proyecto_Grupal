<?php

namespace App\Domains\Financial\Enums;

/**
 * Estado de la comparación contra caja (2.8) en una conciliación.
 */
enum CashReconciliationStatus: string
{
    // 2.8 aún no está integrado en este corte: no se compara caja.
    case NO_INTEGRADA = 'NO_INTEGRADA';
    case COMPARADA = 'COMPARADA';
}
