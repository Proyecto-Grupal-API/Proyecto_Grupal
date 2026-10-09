<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Enums\RefundRequestStatus;
use App\Domains\Financial\Enums\WithdrawalStatus;
use App\Domains\Financial\Models\FinancialRefundRequest;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\FinancialWithdrawalRecovery;
use App\Domains\Financial\Models\Withdrawal;
use App\Domains\Financial\Models\PurchasePayment;
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

                $this->rejectPurchaseAdjustment($lockedOriginal);

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

    public function confirmWithdrawalRecovery(
    FinancialRefundRequest $refundRequest,
    string $recoveryReference,
    string $confirmedBy,
    ?string $notes = null
): FinancialWithdrawalRecovery {
    $recoveryReference = trim($recoveryReference);

    if (
        $recoveryReference === '' ||
        mb_strlen($recoveryReference) > 255
    ) {
        throw new InvalidArgumentException(
            'La referencia de recuperación debe contener entre 1 y 255 caracteres.'
        );
    }

    if (
        trim($confirmedBy) === '' ||
        mb_strlen($confirmedBy) > 255
    ) {
        throw new InvalidArgumentException(
            'El responsable de confirmar la recuperación es obligatorio y no debe superar 255 caracteres.'
        );
    }

    return DB::connection('sqlsrv')->transaction(
        function () use (
            $refundRequest,
            $recoveryReference,
            $confirmedBy,
            $notes
        ) {
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

            // Repetir la misma confirmación no crea otro registro.
            $existing = FinancialWithdrawalRecovery::where(
                'refund_request_id',
                $request->public_id
            )->first();

            if ($existing) {
                if (
                    $existing->recovery_reference !== $recoveryReference ||
                    $existing->confirmed_by !== $confirmedBy ||
                    $existing->amount_cents !== $request->amount_cents
                ) {
                    throw new InvalidArgumentException(
                        'La recuperación ya fue confirmada con datos diferentes.'
                    );
                }

                return $existing;
            }

            if ($request->status !== RefundRequestStatus::APROBADA) {
                throw new InvalidArgumentException(
                    'Solo se puede confirmar la recuperación de una solicitud aprobada.'
                );
            }

            if (
                $original->status !== TransactionStatus::COMPLETADA ||
                $original->reference_type !== 'WITHDRAWAL'
            ) {
                throw new InvalidArgumentException(
                    'La operación original debe ser un retiro completado.'
                );
            }

            $withdrawal = Withdrawal::where(
                'public_id',
                $original->reference_id
            )
                ->lockForUpdate()
                ->firstOrFail();

            $entries = LedgerEntry::where(
                'transaction_id',
                $original->public_id
            )
                ->lockForUpdate()
                ->get();

            $entry = $entries->first();

            if (
                $withdrawal->status !== WithdrawalStatus::COMPLETADA ||
                $entries->count() !== 1 ||
                !$entry ||
                $entry->movement_type !== MovementType::RETIRO ||
                $entry->amount_cents >= 0 ||
                abs($entry->amount_cents) !== $withdrawal->amount_cents ||
                strtolower($entry->wallet_id) !==
                    strtolower($withdrawal->wallet_id) ||
                strtolower($request->wallet_id) !==
                    strtolower($withdrawal->wallet_id)
            ) {
                throw new InvalidArgumentException(
                    'El retiro y sus movimientos contables no corresponden a la solicitud.'
                );
            }

            $wallet = Wallet::where(
                'public_id',
                $withdrawal->wallet_id
            )
                ->lockForUpdate()
                ->firstOrFail();

            if ($wallet->currency !== $withdrawal->currency) {
                throw new InvalidArgumentException(
                    'La moneda del retiro no corresponde a la wallet.'
                );
            }

            if (
                $request->amount_cents <= 0 ||
                $this->requestedRefundAmount($original->public_id) >
                    $withdrawal->amount_cents
            ) {
                throw new InvalidArgumentException(
                    'El monto solicitado supera el saldo disponible para devolución.'
                );
            }

            if (FinancialWithdrawalRecovery::where(
                'recovery_reference',
                $recoveryReference
            )->exists()) {
                throw new InvalidArgumentException(
                    'El comprobante de recuperación ya fue utilizado.'
                );
            }

            return FinancialWithdrawalRecovery::create([
                'public_id' => (string) Str::uuid(),
                'refund_request_id' => $request->public_id,
                'withdrawal_id' => $withdrawal->public_id,
                'amount_cents' => $request->amount_cents,
                'currency' => $withdrawal->currency,
                'recovery_reference' => $recoveryReference,
                'confirmed_by' => $confirmedBy,
                'confirmed_at' => now(),
                'notes' => $notes,
            ]);
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

            $this->rejectPurchaseAdjustment($original);

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

            $entries = LedgerEntry::where(
                'transaction_id',
                $original->public_id
            )
                ->lockForUpdate()
                ->get();

            $entry = $entries->first(
                fn (LedgerEntry $item) => $item->amount_cents < 0
            );

            $isPayment =
                $entries->count() === 1 &&
                $entry &&
                $entry->movement_type === MovementType::PAGO;

            $incomingEntry = $entries->first(
                fn (LedgerEntry $item) =>
                    $item->movement_type === MovementType::TRANSFERENCIA_ENTRADA &&
                    $item->amount_cents > 0
            );

            $isTransfer =
                $entries->count() === 2 &&
                $entry &&
                $incomingEntry &&
                $entry->movement_type === MovementType::TRANSFERENCIA_SALIDA &&
                $incomingEntry->amount_cents === abs($entry->amount_cents) &&
                strtolower($incomingEntry->wallet_id) !==
                     strtolower($entry->wallet_id);

            $isWithdrawal =
                $entries->count() === 1 &&
                $entry &&
                $entry->movement_type === MovementType::RETIRO &&
                $original->reference_type === 'WITHDRAWAL';


            if (!$isPayment && !$isTransfer && !$isWithdrawal) {
                throw new InvalidArgumentException(
                    'Esta ejecución solo admite pagos, transferencias o retiros con recuperación confirmada.'
               );
            }

            if (
                strtolower($request->wallet_id) !==
                strtolower($entry->wallet_id)
            ) {
                throw new InvalidArgumentException(
                    'La wallet de la solicitud no corresponde al cargo original.'
                );
            }

            if  (
                 $request->amount_cents <= 0 ||
                 $this->requestedRefundAmount($original->public_id) >
                 abs($entry->amount_cents)
            )  {
                throw new InvalidArgumentException(
                    'El monto solicitado supera el saldo disponible para devolución.'
                );
            }

            if ($this->findByIdempotencyKey($idempotencyKey)) {
                throw new InvalidArgumentException(
                    'La clave de idempotencia ya pertenece a otra operación.'
                );
            }

            $recovery = null;

if ($isWithdrawal) {
    $withdrawal = Withdrawal::where(
        'public_id',
        $original->reference_id
    )
        ->lockForUpdate()
        ->firstOrFail();

    $recovery = FinancialWithdrawalRecovery::where(
        'refund_request_id',
        $request->public_id
    )
        ->lockForUpdate()
        ->first();

    if (!$recovery) {
        throw new InvalidArgumentException(
            'Debe confirmarse la recuperación del dinero externo antes de devolver el retiro.'
        );
    }

    if (
        $withdrawal->status !== WithdrawalStatus::COMPLETADA ||
        strtolower($withdrawal->wallet_id) !==
            strtolower($request->wallet_id) ||
        abs($entry->amount_cents) !== $withdrawal->amount_cents ||
        strtolower($recovery->withdrawal_id) !==
            strtolower($withdrawal->public_id) ||
        $recovery->amount_cents !== $request->amount_cents ||
        $recovery->currency !== $withdrawal->currency ||
        !$recovery->confirmed_at ||
        trim($recovery->confirmed_by) === '' ||
        trim($recovery->recovery_reference) === ''
    ) {
        throw new InvalidArgumentException(
            'La recuperación registrada no corresponde al retiro o al monto solicitado.'
        );
    }

    $withdrawalWallet = Wallet::where(
        'public_id',
        $withdrawal->wallet_id
    )
        ->lockForUpdate()
        ->firstOrFail();

    if ($withdrawalWallet->currency !== $recovery->currency) {
        throw new InvalidArgumentException(
            'La moneda recuperada no corresponde a la wallet.'
        );
    }
}

            $metadata = [
                'operation' => 'DEVOLUCION',
                'refund_request_id' => $request->public_id,
                'original_transaction_id' => $original->public_id,
                'amount_cents' => $request->amount_cents,
                'reviewed_by' => $request->reviewed_by,
                'executed_by' => $executedBy,
                'refund_type' => $isTransfer
                    ? 'TRANSFERENCIA'
                    : ($isWithdrawal ? 'RETIRO' : 'PAGO'),
                'recovery_id' => $recovery?->public_id,
                'recovery_reference' => $recovery?->recovery_reference,
            ];

            $ledger = app(LedgerService::class);

            if ($isTransfer) {
                $wallets = Wallet::whereIn('public_id', [
                    $entry->wallet_id,
                    $incomingEntry->wallet_id,
                ])
                    ->orderBy('public_id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy(
                        fn (Wallet $item) => strtolower($item->public_id)
                    );

                 $originalSender = $wallets->get(
                     strtolower($entry->wallet_id)
                 );

                 $originalReceiver = $wallets->get(
                     strtolower($incomingEntry->wallet_id)
                 );

             if (!$originalSender || !$originalReceiver) {
                 throw new InvalidArgumentException(
                     'No fue posible localizar las wallets de la transferencia.'
                 );
             }

             $transaction = $ledger->transfer(
                 sourceWallet: $originalReceiver,
                 destinationWallet: $originalSender,
                 amountCents: $request->amount_cents,
                 idempotencyKey: $idempotencyKey,
                 referenceType: 'DEVOLUCION',
                 referenceId: $request->public_id,
                 metadata: $metadata
             );

             LedgerEntry::where(
                 'transaction_id',
                 $transaction->public_id
             )->update([
                 'movement_type' => MovementType::DEVOLUCION->value,
             ]);
         } else {
             $wallet = Wallet::where(
                 'public_id',
                 $request->wallet_id
             )->firstOrFail();

             $transaction = $ledger->credit(
                 wallet: $wallet,
                 amountCents: $request->amount_cents,
                 movementType: MovementType::DEVOLUCION,
                 idempotencyKey: $idempotencyKey,
                 referenceType: 'DEVOLUCION',
                 referenceId: $request->public_id,
                 metadata: $metadata
             );
           }

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
        ?string $reason = null,
        ?string $executedBy = null
    ): FinancialTransaction {
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 255) {
            throw new InvalidArgumentException('La clave de idempotencia es obligatoria y admite hasta 255 caracteres.');
        }
        try {
            return DB::connection('sqlsrv')->transaction(
                function () use (
                    $originalTransaction,
                    $idempotencyKey,
                    $reason,
                    $executedBy
                ) {
                    $lockedOriginal = FinancialTransaction::where(
                        'public_id',
                        $originalTransaction->public_id
                    )
                        ->lockForUpdate()
                        ->firstOrFail();

                    $existingTransaction = $this->findByIdempotencyKey(
                        $idempotencyKey
                    );

                    if ($existingTransaction) {
                        if (!$this->matchesOperation(
                            $existingTransaction,
                            'REVERSO',
                            $originalTransaction->public_id
                        ) || $executedBy === null || trim($executedBy) === ''
                            || $reason === null || trim($reason) === ''
                            || $existingTransaction->status !== TransactionStatus::COMPLETADA
                            || ($existingTransaction->metadata['executed_by'] ?? null) !== $executedBy
                            || ($existingTransaction->metadata['reason'] ?? null) !== $reason
                            || $lockedOriginal->status !== TransactionStatus::REVERTIDA) {
                            throw new InvalidArgumentException(
                                'La clave de idempotencia ya pertenece a otra operación.'
                            );
                        }

                        return $existingTransaction;
                    }

                    if (
                        $lockedOriginal->status !==
                        TransactionStatus::COMPLETADA
                    ) {
                        throw new InvalidArgumentException(
                            'Solo se pueden reversar operaciones completadas.'
                        );
                    }

                    $this->rejectPurchaseAdjustment($lockedOriginal);

                    if ($this->requestedRefundAmount($lockedOriginal->public_id) > 0) {
                        throw new InvalidArgumentException(
                            'No se puede reversar una operación con devoluciones pendientes, aprobadas o completadas.'
                        );
                     }

                    if ($executedBy === null || trim($executedBy) === '' || mb_strlen($executedBy) > 255
                        || $reason === null || trim($reason) === '' || mb_strlen($reason) > 1000) {
                        throw new InvalidArgumentException('El responsable y el motivo del reverso son obligatorios.');
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

                    if (in_array($lockedOriginal->reference_type, ['TOPUP', 'WITHDRAWAL', 'DEVOLUCION', 'REVERSO', 'PURCHASE_REFUND', 'REFUND_REQUEST', 'PURCHASE_REFUND_REQUEST'], true)) {
                        throw new InvalidArgumentException('Esta operación requiere su flujo específico de devolución o corrección.');
                    }

                    // Solo pagos simples o transferencias completas; nunca movimientos externos,
                    // ajustes, devoluciones, reversos anteriores o cambios de saldo retenido.
                    $payment = $entries->count() === 1
                        && $entries->first()->movement_type === MovementType::PAGO
                        && $entries->first()->amount_cents < 0;
                    $transfer = $entries->count() === 2
                        && $entries->where('movement_type', MovementType::TRANSFERENCIA_SALIDA)->count() === 1
                        && $entries->where('movement_type', MovementType::TRANSFERENCIA_ENTRADA)->count() === 1
                        && $entries->where('movement_type', MovementType::TRANSFERENCIA_SALIDA)->first()->amount_cents < 0
                        && $entries->where('movement_type', MovementType::TRANSFERENCIA_ENTRADA)->first()->amount_cents > 0
                        && $entries->sum('amount_cents') === 0
                        && $entries->pluck('wallet_id')->map(fn ($id) => strtolower($id))->unique()->count() === 2;
                    if (!$payment && !$transfer) {
                        throw new InvalidArgumentException('Esta operación requiere su flujo específico de devolución o corrección.');
                    }
                    foreach ($entries as $entry) {
                        if (($entry->held_delta_cents ?? 0) !== 0
                            || ($entry->available_delta_cents ?? $entry->amount_cents) !== $entry->amount_cents) {
                            throw new InvalidArgumentException('No se pueden reversar movimientos de saldo retenido.');
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
                        ->keyBy(fn ($wallet) => strtolower($wallet->public_id));

                    if ($wallets->count() !== count($walletIds)
                        || $wallets->pluck('currency')->unique()->count() !== 1) {
                        throw new InvalidArgumentException('Las wallets deben existir y compartir la misma moneda.');
                    }
                    foreach ($entries as $entry) {
                        $wallet = $wallets->get(
                            strtolower($entry->wallet_id)
                        );

                        if (!$wallet) {
                            throw new InvalidArgumentException(
                                'No fue posible localizar una wallet del movimiento original.'
                            );
                        }

                        if ($wallet->available_balance_cents < 0 || $wallet->held_balance_cents < 0) {
                            throw new InvalidArgumentException('La wallet presenta saldos inconsistentes.');
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
                            'executed_by' => $executedBy,
                            'original_transaction_id' => strtolower($lockedOriginal->public_id),
                        ],
                    ]);

                    foreach ($entries as $entry) {
                        $wallet = $wallets->get(
                            strtolower($entry->wallet_id)
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
                            'available_delta_cents' => $reverseAmount,
                            'held_delta_cents' => 0,
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
        } catch (\Illuminate\Database\UniqueConstraintViolationException $exception) {
            throw new InvalidArgumentException('La clave de idempotencia ya pertenece a otra operación.', 0, $exception);
        }
    }

    private function rejectPurchaseAdjustment(
        FinancialTransaction $transaction
    ): void {
        if (str_starts_with((string) $transaction->reference_type, 'WALLET_HOLD_')) {
            throw new InvalidArgumentException('Las retenciones se administran mediante WalletHoldService.');
        }
        if (
            $transaction->reference_type === 'PURCHASE' &&
            PurchasePayment::where(
                'idempotency_key',
                $transaction->idempotency_key
            )->exists()
    ) {
        throw new InvalidArgumentException(
            'Los pagos con bonos deben devolverse mediante el flujo de devolución de compra.'
        );
    }
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
