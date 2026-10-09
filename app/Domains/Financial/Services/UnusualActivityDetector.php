<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\TransactionAlert;
use App\Domains\Financial\Models\Wallet;

/**
 * Revisa una transacción ya confirmada y registra una alerta por cada
 * límite (BLOQUEAR o ALERTAR) que sus movimientos rebasaron.
 *
 * Un límite BLOQUEAR rebasado aquí indica una operación que no pasó por
 * el control previo (pagos, transferencias) o una carrera concurrente;
 * por eso también se alerta.
 *
 * El valor observado solo acumula movimientos anteriores al revisado
 * (id interno menor), para que la evidencia sea reproducible.
 *
 * Si un límite no puede evaluarse (proveedor de roles caído) se registra
 * una alerta CONTROL_NO_EVALUADO en lugar de omitir el control.
 *
 * Nunca modifica saldos ni ledger.
 */
class UnusualActivityDetector
{
    public function __construct(
        private readonly FinancialLimitService $limits,
        private readonly TransactionAlertService $alerts
    ) {
    }

    /**
     * @return array<int, TransactionAlert>
     */
    public function inspectTransaction(FinancialTransaction $transaction): array
    {
        if ($transaction->status !== TransactionStatus::COMPLETADA) {
            return [];
        }

        $entries = LedgerEntry::where('transaction_id', $transaction->public_id)
            ->orderBy('id')
            ->get();

        $alerts = [];

        foreach ($entries as $entry) {
            $amount = abs((int) $entry->amount_cents);

            if ($amount === 0) {
                continue;
            }

            $wallet = Wallet::where('public_id', $entry->wallet_id)->first();

            if (!$wallet) {
                continue;
            }

            try {
                $evaluation = $this->limits->evaluate(
                    wallet: $wallet,
                    operation: $entry->movement_type,
                    amountCents: $amount,
                    at: $entry->created_at,
                    excludeLedgerEntryId: $entry->public_id,
                    beforeLedgerRowId: (int) $entry->id
                );
            } catch (FinancialDependencyUnavailableException $exception) {
                // No se omite el control en silencio: queda una alerta
                // CONTROL_NO_EVALUADO ligada al movimiento.
                report($exception);

                $alerts[] = $this->alerts->recordUnevaluated(
                    $transaction,
                    $entry,
                    $exception->getMessage()
                );

                continue;
            }

            foreach ($evaluation->violations as $violation) {
                $alerts[] = $this->alerts->recordLimitExceeded(
                    $transaction,
                    $entry,
                    $violation
                );
            }
        }

        return $alerts;
    }
}
