<?php

namespace App\Domains\Financial\Observers;

use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Services\UnusualActivityDetector;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Throwable;

/**
 * Punto de integración de 2.10 con el núcleo financiero sin modificar
 * LedgerService.
 *
 * - Se ejecuta DESPUÉS del commit: si la operación se revierte no se
 *   genera alerta, y la alerta nunca forma parte de la transacción
 *   contable.
 * - Un fallo al registrar alertas se reporta en el log pero no afecta
 *   la operación financiera, que ya quedó confirmada.
 */
class FinancialTransactionObserver implements ShouldHandleEventsAfterCommit
{
    public function created(FinancialTransaction $transaction): void
    {
        if ($transaction->status === TransactionStatus::COMPLETADA) {
            $this->inspect($transaction);
        }
    }

    public function updated(FinancialTransaction $transaction): void
    {
        if (
            $transaction->wasChanged('status')
            && $transaction->status === TransactionStatus::COMPLETADA
        ) {
            $this->inspect($transaction);
        }
    }

    private function inspect(FinancialTransaction $transaction): void
    {
        try {
            app(UnusualActivityDetector::class)
                ->inspectTransaction($transaction);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
