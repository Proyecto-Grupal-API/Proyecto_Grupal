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

    public function approveRefund(
        FinancialRefundRequest $refundRequest,
        string $reviewedBy,
        ?string $reviewReason = null
    ): FinancialRefundRequest {


    if (trim($reviewedBy) === '') {
        throw new InvalidArgumentException(
            'El administrador responsable es obligatorio.'
        );
    }

    return DB::connection('sqlsrv')->transaction(
        function () use (
            $refundRequest,
            $reviewedBy,
            $reviewReason
        ) {


            $lockedRequest = FinancialRefundRequest::where(
                'public_id',
                $refundRequest->public_id
            )
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lockedRequest->status !==
                RefundRequestStatus::PENDIENTE
            ) {
                throw new InvalidArgumentException(
                    'Solo se pueden aprobar solicitudes pendientes.'
                );
            }

            // Comprobar la transacción financiera relacionada.

            $transaction = FinancialTransaction::where(
                'public_id',
                $lockedRequest->request_transaction_id
            )
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $transaction->status !==
                TransactionStatus::PENDIENTE
            ) {
                throw new InvalidArgumentException(
                    'La transacción de devolución no está pendiente.'
                );
            }

            // Registrar la aprobación administrativa.

            $lockedRequest->status =
                RefundRequestStatus::APROBADA;

            $lockedRequest->reviewed_by = $reviewedBy;

            $lockedRequest->review_reason = $reviewReason;

            $lockedRequest->reviewed_at = now();

            $lockedRequest->save();

            return $lockedRequest;
        }
    );
}

    public function rejectRefund(
        FinancialRefundRequest $refundRequest,
        string $reviewedBy,
        string $reviewReason
    ): FinancialRefundRequest {

        if (trim($reviewedBy) === '') {
            throw new InvalidArgumentException(
                'El administrador responsable es obligatorio.'
            );
        }

        if (trim($reviewReason) === '') {
            throw new InvalidArgumentException(
                'El motivo del rechazo es obligatorio.'
            );
        }

        return DB::connection('sqlsrv')->transaction(
            function () use (
                $refundRequest,
                $reviewedBy,
                $reviewReason
             ) {

           
                $lockedRequest = FinancialRefundRequest::where(
                    'public_id',
                    $refundRequest->public_id
                 )
                    ->lockForUpdate()
                    ->firstOrFail();


                 if (
                     $lockedRequest->status !==
                     RefundRequestStatus::PENDIENTE
                 ) {
                     throw new InvalidArgumentException(
                         'Solo se pueden rechazar solicitudes pendientes.'
                 );
            }


            $transaction = FinancialTransaction::where(
                'public_id',
                $lockedRequest->request_transaction_id
            )
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $transaction->status !==
                TransactionStatus::PENDIENTE
            ) {
                throw new InvalidArgumentException(
                    'La transacción de devolución no está pendiente.'
                );
            }


            $lockedRequest->status =
                RefundRequestStatus::RECHAZADA;

            $lockedRequest->reviewed_by = $reviewedBy;

            $lockedRequest->review_reason = $reviewReason;

            $lockedRequest->reviewed_at = now();

            $lockedRequest->save();

            $transaction->status = TransactionStatus::FALLIDA;

            $transaction->save();

            return $lockedRequest;
        }
    );
}

    public function completeRefund(
        FinancialRefundRequest $refundRequest,
        string $idempotencyKey,
        ?string $executedBy = null
    ): FinancialTransaction {

    if (trim($idempotencyKey) === '') {
        throw new InvalidArgumentException(
            'La clave de idempotencia es obligatoria.'
        );
    }

    return DB::connection('sqlsrv')->transaction(
        function () use ($refundRequest, $idempotencyKey, $executedBy) {
            // Bloquear primero la operación original.
            $original = FinancialTransaction::where(
                'public_id',
                $refundRequest->original_transaction_id
            )
                ->lockForUpdate()
                ->firstOrFail();

            $request = FinancialRefundRequest::where(
                'public_id',
                $refundRequest->public_id
            )
                ->lockForUpdate()
                ->firstOrFail();

            if (
                strtolower($request->original_transaction_id) !==
                strtolower($original->public_id)
            ) {
                throw new InvalidArgumentException(
                    'La solicitud no corresponde a la operación original.'
                );
            }

            // Un reintento devuelve la operación ya realizada.
            if ($request->status === RefundRequestStatus::COMPLETADA) {
                $completed = FinancialTransaction::where(
                    'public_id',
                    $request->financial_transaction_id
                )->firstOrFail();

                if (
                    $completed->idempotency_key !== $idempotencyKey ||
                    $completed->status !== TransactionStatus::COMPLETADA ||
                    !$this->matchesOperation(
                        $completed,
                        'DEVOLUCION',
                        $request->public_id
                    )
                ) {
                    throw new InvalidArgumentException(
                        'La devolución ya fue completada con otra clave o presenta datos inconsistentes.'
                    );
                }

                return $completed;
            }

            if ($request->status !== RefundRequestStatus::APROBADA) {
                throw new InvalidArgumentException(
                    'Solo se pueden ejecutar solicitudes aprobadas.'
                );
            }

            if ($original->status !== TransactionStatus::COMPLETADA) {
                throw new InvalidArgumentException(
                    'La operación original ya no admite una devolución.'
                );
            }

            $requestTransaction = FinancialTransaction::where(
                'public_id',
                $request->request_transaction_id
            )
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $requestTransaction->status !== TransactionStatus::PENDIENTE ||
                !$this->matchesOperation(
                    $requestTransaction,
                    'REFUND_REQUEST',
                    $original->public_id
                )
            ) {
                throw new InvalidArgumentException(
                    'La transacción de solicitud no es válida para ejecutarse.'
                );
            }

            // Esta implementación admite un pago con un solo cargo.
            $entries = LedgerEntry::where(
                'transaction_id',
                $original->public_id
            )
                ->lockForUpdate()
                ->get();

            $entry = $entries->first();

            if (
                $entries->count() !== 1 ||
                !$entry ||
                $entry->movement_type !== MovementType::PAGO ||
                $entry->amount_cents >= 0
            ) {
                throw new InvalidArgumentException(
                    'Esta ejecución solo admite pagos con un único cargo contable.'
                );
            }

            if (
                strtolower($request->wallet_id) !==
                strtolower($entry->wallet_id)
            ) {
                throw new InvalidArgumentException(
                    'La wallet de la solicitud no corresponde al pago original.'
                );
            }

            if (
                $request->amount_cents <= 0 ||
                $this->requestedRefundAmount($original->public_id) >
                abs($entry->amount_cents)
            ) {
                throw new InvalidArgumentException(
                    'El monto solicitado supera el saldo disponible para devolución.'
                );
            }

            if ($this->findByIdempotencyKey($idempotencyKey)) {
                throw new InvalidArgumentException(
                    'La clave de idempotencia ya pertenece a otra operación.'
                );
            }

            $wallet = Wallet::where(
                'public_id',
                $request->wallet_id
            )->firstOrFail();

            // Abono y registro contable mediante el Ledger.
            $transaction = app(LedgerService::class)->credit(
                wallet: $wallet,
                amountCents: $request->amount_cents,
                movementType: MovementType::DEVOLUCION,
                idempotencyKey: $idempotencyKey,
                referenceType: 'DEVOLUCION',
                referenceId: $request->public_id,
                metadata: [
                    'operation' => 'DEVOLUCION',
                    'refund_request_id' => $request->public_id,
                    'original_transaction_id' => $original->public_id,
                    'amount_cents' => $request->amount_cents,
                    'reviewed_by' => $request->reviewed_by,
                    'executed_by' => $executedBy,
                ]
            );

            $transaction->original_transaction_id = $original->public_id;
            $transaction->save();

            $request->status = RefundRequestStatus::COMPLETADA;
            $request->completed_at = now();
            $request->financial_transaction_id = $transaction->public_id;
            $request->save();

            $requestTransaction->status = TransactionStatus::COMPLETADA;
            $requestTransaction->save();

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

                if ($this->requestedRefundAmount($lockedOriginal->public_id) > 0) {
                    throw new InvalidArgumentException(
                        'No se puede reversar una operación con devoluciones pendientes, aprobadas o completadas.'
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

        // Sumar las solicitudes que todavía
        // comprometen el importe del pago original.

        return (int) FinancialRefundRequest::where(
            'original_transaction_id',
            $originalTransactionId
        )
            ->whereIn('status', [
                RefundRequestStatus::PENDIENTE->value,
                RefundRequestStatus::APROBADA->value,
                RefundRequestStatus::COMPLETADA->value,
            ])
            ->sum('amount_cents');
    }
}