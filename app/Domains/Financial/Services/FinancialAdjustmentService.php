<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Enums\RefundRequestStatus;
use App\Domains\Financial\Models\FinancialRefundRequest;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FinancialAdjustmentService
{
    public function refund(
        FinancialTransaction $originalTransaction,
        int $amountCents,
        string $idempotencyKey,
        string $requestedBy,
        ?string $reason = null
    ): FinancialTransaction {

        if ($amountCents <= 0) {
            throw new InvalidArgumentException(
                'El monto de la devolución debe ser mayor que cero.'
            );
        }

        if (trim($requestedBy) === '') {
            throw new InvalidArgumentException(
                'El solicitante de la devolución es obligatorio.'
            );
        }

        return DB::connection('sqlsrv')->transaction(
            function () use (
                $originalTransaction,
                $amountCents,
                $idempotencyKey,
                $requestedBy,
                $reason
         ) {
                $existingTransaction = $this->findByIdempotencyKey(
                    $idempotencyKey
                );

                if ($existingTransaction) {
                    if (!$this->matchesOperation(
                        $existingTransaction,
                        'REFUND_REQUEST',
                        $originalTransaction->public_id
                    )) {
                        throw new InvalidArgumentException(
                            'La clave de idempotencia ya pertenece a otra operación.'
                        );
                    }

                    $existingAmountCents = (int) (
                        $existingTransaction->metadata['amount_cents'] ?? 0
                    );

                 if ($existingAmountCents !== $amountCents) {
                     throw new InvalidArgumentException(
                         'La clave de idempotencia ya fue utilizada con un monto diferente.'
                     );
                 }


                 $existingRefundRequest = FinancialRefundRequest::where(
                     'request_transaction_id',
                     $existingTransaction->public_id
                 )->first();

                 if (!$existingRefundRequest) {
                     throw new InvalidArgumentException(
                         'La solicitud de devolución no tiene un registro administrativo.'
                     );
                 }


                 if ($existingRefundRequest->requested_by !== $requestedBy) {
                     throw new InvalidArgumentException(
                         'La clave de idempotencia ya pertenece a otro solicitante.'
                     );
                 }

                 return $existingTransaction;
                }

                $lockedOriginal = FinancialTransaction::where(
                    'public_id',
                    $originalTransaction->public_id
                )
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $lockedOriginal->status !==
                    TransactionStatus::COMPLETADA
                ) {
                    throw new InvalidArgumentException(
                        'Solo se pueden devolver operaciones completadas.'
                    );
                }

                $originalEntry = $this->getRefundableEntry(
                    $lockedOriginal->public_id
                );

                $originalAmountCents = abs(
                    $originalEntry->amount_cents
                );

                $requestedCents = $this->requestedRefundAmount(
                    $lockedOriginal->public_id
                );

                if (
                    ($requestedCents + $amountCents) >
                    $originalAmountCents
                ) {
                    throw new InvalidArgumentException(
                        'El monto solicitado supera el saldo disponible para devolución.'
                    );
                }

                $transaction = FinancialTransaction::create([
                    'public_id' => (string) Str::uuid(),
                    'idempotency_key' => $idempotencyKey,
                    'status' => TransactionStatus::PENDIENTE,
                    'reference_type' => 'REFUND_REQUEST',
                    'reference_id' => $lockedOriginal->public_id,
                    'original_transaction_id' => $lockedOriginal->public_id,
                    'metadata' => [
                        'operation' => 'REFUND_REQUEST',
                        'amount_cents' => $amountCents,
                        'wallet_id' => $originalEntry->wallet_id,
                        'reason' => $reason,
                    ],
                ]);

                FinancialRefundRequest::create([
                    'public_id' => (string) Str::uuid(),
                    'request_transaction_id' => $transaction->public_id,
                    'original_transaction_id' => $lockedOriginal->public_id,
                    'wallet_id' => $originalEntry->wallet_id,
                    'amount_cents' => $amountCents,
                    'status' => RefundRequestStatus::PENDIENTE,
                    'requested_by' => $requestedBy,
                    'reason' => $reason,
                 ]);

                 return $transaction;
            }
        );
    }

    public function reverse(
        FinancialTransaction $originalTransaction,
        string $idempotencyKey,
        ?string $reason = null
    ): FinancialTransaction {
        return DB::connection('sqlsrv')->transaction(
            function () use (
                $originalTransaction,
                $idempotencyKey,
                $reason
            ) {
                $existingTransaction = $this->findByIdempotencyKey(
                    $idempotencyKey
                );

                if ($existingTransaction) {
                    if (!$this->matchesOperation(
                        $existingTransaction,
                        'REVERSO',
                        $originalTransaction->public_id
                    )) {
                        throw new InvalidArgumentException(
                            'La clave de idempotencia ya pertenece a otra operación.'
                        );
                    }

                    return $existingTransaction;
                }

                $lockedOriginal = FinancialTransaction::where(
                    'public_id',
                    $originalTransaction->public_id
                )
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $lockedOriginal->status !==
                    TransactionStatus::COMPLETADA
                ) {
                    throw new InvalidArgumentException(
                        'Solo se pueden reversar operaciones completadas.'
                    );
                }

                $alreadyReversed = FinancialTransaction::where(
                    'original_transaction_id',
                    $lockedOriginal->public_id
                )
                    ->where('reference_type', 'REVERSO')
                    ->exists();

                if ($alreadyReversed) {
                    throw new InvalidArgumentException(
                        'La operación ya fue reversada.'
                    );
                }

                $entries = LedgerEntry::where(
                    'transaction_id',
                    $lockedOriginal->public_id
                )
                    ->orderBy('wallet_id')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($entries->isEmpty()) {
                    throw new InvalidArgumentException(
                        'La operación no tiene movimientos contables para reversar.'
                    );
                }

                foreach ($entries as $entry) {
                    if (in_array(
                        $entry->movement_type,
                        [
                            MovementType::RETENCION,
                            MovementType::LIBERACION,
                        ],
                        true
                    )) {
                        throw new InvalidArgumentException(
                            'Las retenciones deben liberarse con la operación de liberación correspondiente.'
                        );
                    }
                }

                $walletIds = $entries
                    ->pluck('wallet_id')
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                $wallets = Wallet::whereIn(
                    'public_id',
                    $walletIds
                )
                    ->orderBy('public_id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('public_id');

                foreach ($entries as $entry) {
                    $wallet = $wallets->get(
                        $entry->wallet_id
                    );

                    if (!$wallet) {
                        throw new InvalidArgumentException(
                            'No fue posible localizar una wallet del movimiento original.'
                        );
                    }

                    if (
                        $entry->amount_cents > 0 &&
                        $wallet->available_balance_cents <
                        $entry->amount_cents
                    ) {
                        throw new InvalidArgumentException(
                            'Saldo insuficiente para completar el reverso.'
                        );
                    }
                }

                $transaction = FinancialTransaction::create([
                    'public_id' => (string) Str::uuid(),
                    'idempotency_key' => $idempotencyKey,
                    'status' => TransactionStatus::PENDIENTE,
                    'reference_type' => 'REVERSO',
                    'reference_id' => $lockedOriginal->public_id,
                    'original_transaction_id' =>
                        $lockedOriginal->public_id,
                    'metadata' => [
                        'operation' => 'REVERSO',
                        'reason' => $reason,
                    ],
                ]);

                foreach ($entries as $entry) {
                    $wallet = $wallets->get(
                        $entry->wallet_id
                    );

                    $reverseAmount =
                        -$entry->amount_cents;

                    if ($reverseAmount > 0) {
                        $wallet->increment(
                            'available_balance_cents',
                            $reverseAmount
                        );
                    } else {
                        $wallet->decrement(
                            'available_balance_cents',
                            abs($reverseAmount)
                        );
                    }

                    $wallet->refresh();

                    LedgerEntry::create([
                        'public_id' => (string) Str::uuid(),
                        'transaction_id' =>
                            $transaction->public_id,
                        'wallet_id' => $wallet->public_id,
                        'movement_type' =>
                            MovementType::REVERSO,
                        'amount_cents' => $reverseAmount,
                        'balance_after_cents' =>
                            $wallet->available_balance_cents,
                        'available_balance_after_cents' =>
                            $wallet->available_balance_cents,
                        'held_balance_after_cents' =>
                            $wallet->held_balance_cents,
                    ]);
                }

                $lockedOriginal->status =
                    TransactionStatus::REVERTIDA;

                $lockedOriginal->save();

                $transaction->status =
                    TransactionStatus::COMPLETADA;

                $transaction->save();

                return $transaction;
            }
        );
    }

    private function findByIdempotencyKey(
        string $idempotencyKey
    ): ?FinancialTransaction {
        return FinancialTransaction::where(
            'idempotency_key',
            $idempotencyKey
        )->first();
    }

    private function matchesOperation(
        FinancialTransaction $transaction,
        string $referenceType,
        string $referenceId
    ): bool {
        return $transaction->reference_type === $referenceType
            && strtolower(
                (string) $transaction->reference_id
            ) === strtolower($referenceId);
    }

    private function getRefundableEntry(
        string $transactionId
    ): LedgerEntry {
        $entry = LedgerEntry::where(
            'transaction_id',
            $transactionId
        )
            ->where('amount_cents', '<', 0)
            ->whereIn('movement_type', [
                MovementType::PAGO->value,
                MovementType::RETIRO->value,
                MovementType::TRANSFERENCIA_SALIDA->value,
            ])
            ->orderBy('id')
            ->first();

        if (!$entry) {
            throw new InvalidArgumentException(
                'La operación no admite una devolución.'
            );
        }

        return $entry;
    }

    private function requestedRefundAmount(
        string $originalTransactionId
    ): int {
        return (int) FinancialTransaction::where(
            'original_transaction_id',
            $originalTransactionId
        )
            ->where(
                'reference_type',
                'REFUND_REQUEST'
            )
            ->get()
            ->sum(
                function (
                    FinancialTransaction $transaction
                ) {
                    return (int) (
                        $transaction
                            ->metadata['amount_cents']
                        ?? 0
                    );
                }
            );
    }
}