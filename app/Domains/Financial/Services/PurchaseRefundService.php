<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\RefundRequestStatus;
use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Enums\BonusMovementType;
use App\Domains\Financial\Enums\BonusStatus;
use App\Domains\Financial\Models\Bonus;
use App\Domains\Financial\Models\BonusLedgerEntry;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\FinancialRefundRequest;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\PurchasePayment;
use App\Domains\Financial\Models\PurchaseRefundBonus;
use App\Domains\Financial\Models\PurchaseRefundRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PurchaseRefundService
{
    public function request(
        PurchasePayment $payment,
        int $walletAmountCents,
        array $bonusRefunds,
        string $idempotencyKey,
        string $requestedBy,
        ?string $reason = null
    ): PurchaseRefundRequest {
        if ($walletAmountCents < 0) {
            throw new InvalidArgumentException(
                'El importe de wallet no puede ser negativo.'
            );
        }

        if (
            trim($idempotencyKey) === '' ||
            mb_strlen($idempotencyKey) > 255
        ) {
            throw new InvalidArgumentException(
                'La clave de idempotencia debe contener entre 1 y 255 caracteres.'
            );
        }

        if (
            trim($requestedBy) === '' ||
            mb_strlen($requestedBy) > 255
        ) {
            throw new InvalidArgumentException(
                'El solicitante es obligatorio y no debe superar 255 caracteres.'
            );
        }

        $allocations = $this->normalizeBonusRefunds($bonusRefunds);

        if ($walletAmountCents === 0 && $allocations === []) {
            throw new InvalidArgumentException(
                'La devolución debe incluir un importe mayor que cero.'
            );
        }

        return DB::connection('sqlsrv')->transaction(
            function () use (
                $payment,
                $walletAmountCents,
                $allocations,
                $idempotencyKey,
                $requestedBy,
                $reason
            ) {
                // Serializar las solicitudes sobre la misma compra.
                $lockedPayment = PurchasePayment::where(
                    'public_id',
                    $payment->public_id
                )
                    ->lockForUpdate()
                    ->firstOrFail();

                $existingTransaction = FinancialTransaction::where(
                    'idempotency_key',
                    $idempotencyKey
                )->first();

                if ($existingTransaction) {
                    $existing = PurchaseRefundRequest::where(
                        'request_transaction_id',
                        $existingTransaction->public_id
                    )->first();

                    if (
                        !$existing ||
                        $existingTransaction->reference_type !==
                            'PURCHASE_REFUND_REQUEST' ||
                        strtolower((string) $existingTransaction->reference_id) !==
                            strtolower($lockedPayment->public_id) ||
                        strtolower($existing->purchase_payment_id) !==
                            strtolower($lockedPayment->public_id) ||
                        $existing->wallet_amount_cents !== $walletAmountCents ||
                        $existing->requested_by !== $requestedBy
                    ) {
                        throw new InvalidArgumentException(
                            'La clave de idempotencia ya fue utilizada con datos diferentes.'
                        );
                    }

                    $existingAllocations = [];

                    foreach ($existing->bonuses()->get() as $line) {
                        $existingAllocations[strtolower($line->bonus_id)] =
                            $line->amount_cents;
                    }

                    ksort($existingAllocations);

                    if ($existingAllocations !== $allocations) {
                        throw new InvalidArgumentException(
                            'La clave de idempotencia ya fue utilizada con un reparto diferente.'
                        );
                    }

                    return $existing;
                }

                if ($lockedPayment->status !== 'COMPLETADO') {
                    throw new InvalidArgumentException(
                        'Solo se pueden devolver compras completadas.'
                    );
                }

                $paidBonuses = $this->paidBonusAmounts($lockedPayment);

                // Verificar el cargo original si hubo pago con wallet.
                if ($lockedPayment->wallet_amount_cents > 0) {
                    $original = FinancialTransaction::where(
                        'idempotency_key',
                        $lockedPayment->idempotency_key
                    )
                        ->lockForUpdate()
                        ->firstOrFail();

                    $entries = LedgerEntry::where(
                        'transaction_id',
                        $original->public_id
                    )->get();

                    $entry = $entries->first();

                    if (
                        $original->status !== TransactionStatus::COMPLETADA ||
                        $original->reference_type !== 'PURCHASE' ||
                        $entries->count() !== 1 ||
                        !$entry ||
                        $entry->movement_type !== MovementType::PAGO ||
                        $entry->amount_cents !==
                            -$lockedPayment->wallet_amount_cents ||
                        strtolower($entry->wallet_id) !==
                            strtolower($lockedPayment->wallet_id)
                    ) {
                        throw new InvalidArgumentException(
                            'El cargo de wallet no corresponde a la compra completada.'
                        );
                    }

                    // Bloquear compras con devoluciones del flujo anterior.
                    $hasLegacyRefund = FinancialRefundRequest::where(
                        'original_transaction_id',
                        $original->public_id
                    )
                        ->whereIn('status', $this->reservedStatuses())
                        ->exists();

                    if ($hasLegacyRefund) {
                        throw new InvalidArgumentException(
                            'La compra tiene una devolución del flujo anterior que debe conciliarse.'
                        );
                    }
                }

                $reservedRequests = PurchaseRefundRequest::where(
                    'purchase_payment_id',
                    $lockedPayment->public_id
                )->whereIn('status', $this->reservedStatuses());

                $reservedWallet = (int) (clone $reservedRequests)
                    ->sum('wallet_amount_cents');

                if (
                    $walletAmountCents >
                    $lockedPayment->wallet_amount_cents - $reservedWallet
                ) {
                    throw new InvalidArgumentException(
                        'La devolución de wallet supera lo disponible de esta compra.'
                    );
                }

                $reservedRequestIds = (clone $reservedRequests)
                    ->pluck('public_id');

                $bonusTotal = 0;

                foreach ($allocations as $bonusId => $amount) {
                    if (!array_key_exists($bonusId, $paidBonuses)) {
                        throw new InvalidArgumentException(
                            'Un bono solicitado no fue utilizado en esta compra.'
                        );
                    }

                    $reservedBonus = (int) PurchaseRefundBonus::whereIn(
                        'refund_request_id',
                        $reservedRequestIds
                    )
                        ->where('bonus_id', $bonusId)
                        ->sum('amount_cents');

                    if ($amount > $paidBonuses[$bonusId] - $reservedBonus) {
                        throw new InvalidArgumentException(
                            'La devolución de un bono supera lo disponible de esta compra.'
                        );
                    }

                    $bonusTotal += $amount;
                }

                $total = $walletAmountCents + $bonusTotal;

                $transaction = FinancialTransaction::create([
                    'public_id' => (string) Str::uuid(),
                    'idempotency_key' => $idempotencyKey,
                    'status' => TransactionStatus::PENDIENTE,
                    'reference_type' => 'PURCHASE_REFUND_REQUEST',
                    'reference_id' => $lockedPayment->public_id,
                    'metadata' => [
                        'operation' => 'PURCHASE_REFUND_REQUEST',
                        'total_amount_cents' => $total,
                        'wallet_amount_cents' => $walletAmountCents,
                        'bonus_amount_cents' => $bonusTotal,
                        'requested_by' => $requestedBy,
                    ],
                ]);

                $refund = PurchaseRefundRequest::create([
                    'public_id' => (string) Str::uuid(),
                    'purchase_payment_id' => $lockedPayment->public_id,
                    'request_transaction_id' => $transaction->public_id,
                    'wallet_id' => $lockedPayment->wallet_id,
                    'currency' => $lockedPayment->currency,
                    'total_amount_cents' => $total,
                    'wallet_amount_cents' => $walletAmountCents,
                    'bonus_amount_cents' => $bonusTotal,
                    'status' => RefundRequestStatus::PENDIENTE,
                    'requested_by' => $requestedBy,
                    'reason' => $reason,
                ]);

                foreach ($allocations as $bonusId => $amount) {
                    PurchaseRefundBonus::create([
                        'refund_request_id' => $refund->public_id,
                        'bonus_id' => $bonusId,
                        'amount_cents' => $amount,
                    ]);
                }

                return $refund;
            }
        );
    }

     public function complete(
    PurchaseRefundRequest $refundRequest,
    string $idempotencyKey,
    string $executedBy
): FinancialTransaction {
    if (
        trim($idempotencyKey) === '' ||
        mb_strlen($idempotencyKey) > 255 ||
        trim($executedBy) === '' ||
        mb_strlen($executedBy) > 255
    ) {
        throw new InvalidArgumentException(
            'La clave de idempotencia y el ejecutor son obligatorios y no deben superar 255 caracteres.'
        );
    }

    return DB::connection('sqlsrv')->transaction(
        function () use ($refundRequest, $idempotencyKey, $executedBy) {
            $payment = PurchasePayment::where(
                'public_id',
                $refundRequest->purchase_payment_id
            )->lockForUpdate()->firstOrFail();

            $request = PurchaseRefundRequest::where(
                'public_id',
                $refundRequest->public_id
            )->lockForUpdate()->firstOrFail();

            if (
                strtolower($request->purchase_payment_id) !==
                strtolower($payment->public_id)
            ) {
                throw new InvalidArgumentException(
                    'La solicitud no corresponde a la compra original.'
                );
            }

            if ($request->status === RefundRequestStatus::COMPLETADA) {
                $completed = FinancialTransaction::where(
                    'public_id',
                    $request->financial_transaction_id
                )->firstOrFail();

                if (
                    $completed->idempotency_key !== $idempotencyKey ||
                    $completed->status !== TransactionStatus::COMPLETADA ||
                    $completed->reference_type !== 'PURCHASE_REFUND' ||
                    strtolower((string) $completed->reference_id) !==
                        strtolower($request->public_id)
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

            if ($payment->status !== 'COMPLETADO') {
                throw new InvalidArgumentException(
                    'La compra original no admite una devolución.'
                );
            }

            $requestTransaction = FinancialTransaction::where(
                'public_id',
                $request->request_transaction_id
            )->lockForUpdate()->firstOrFail();

            if (
                $requestTransaction->status !== TransactionStatus::PENDIENTE ||
                $requestTransaction->reference_type !== 'PURCHASE_REFUND_REQUEST' ||
                strtolower((string) $requestTransaction->reference_id) !==
                    strtolower($payment->public_id)
            ) {
                throw new InvalidArgumentException(
                    'La transacción de solicitud no es válida para ejecutarse.'
                );
            }

            if (FinancialTransaction::where(
                'idempotency_key',
                $idempotencyKey
            )->exists()) {
                throw new InvalidArgumentException(
                    'La clave de idempotencia ya pertenece a otra operación.'
                );
            }

            $paidBonuses = $this->paidBonusAmounts($payment);
            $lines = $request->bonuses()->lockForUpdate()->get();

            $allocations = $this->normalizeBonusRefunds(
                $lines->map(fn ($line) => [
                    'bonus_id' => $line->bonus_id,
                    'amount_cents' => $line->amount_cents,
                ])->all()
            );

            if (
                $request->wallet_amount_cents < 0 ||
                $request->total_amount_cents <= 0 ||
                array_sum($allocations) !== $request->bonus_amount_cents ||
                $request->wallet_amount_cents + $request->bonus_amount_cents !==
                    $request->total_amount_cents ||
                strtolower($request->wallet_id) !== strtolower($payment->wallet_id) ||
                $request->currency !== $payment->currency
            ) {
                throw new InvalidArgumentException(
                    'El reparto de la devolución es inconsistente.'
                );
            }

            $reservedRequests = PurchaseRefundRequest::where(
                'purchase_payment_id',
                $payment->public_id
            )->whereIn('status', $this->reservedStatuses());

            if (
                (int) (clone $reservedRequests)->sum('wallet_amount_cents') >
                $payment->wallet_amount_cents
            ) {
                throw new InvalidArgumentException(
                    'La devolución de wallet supera lo disponible de esta compra.'
                );
            }

            $reservedIds = (clone $reservedRequests)->pluck('public_id');

            // Si hubo cargo de wallet, debe seguir completado y sin
            // devoluciones del flujo anterior.
            if ($payment->wallet_amount_cents > 0) {
                $original = FinancialTransaction::where(
                    'idempotency_key',
                    $payment->idempotency_key
                )->lockForUpdate()->firstOrFail();

                $entries = LedgerEntry::where(
                    'transaction_id',
                    $original->public_id
                )->get();

                $entry = $entries->first();

                if (
                    $original->status !== TransactionStatus::COMPLETADA ||
                    $original->reference_type !== 'PURCHASE' ||
                    $entries->count() !== 1 ||
                    !$entry ||
                    $entry->movement_type !== MovementType::PAGO ||
                    $entry->amount_cents !== -$payment->wallet_amount_cents ||
                    strtolower($entry->wallet_id) !== strtolower($payment->wallet_id) ||
                    FinancialRefundRequest::where(
                        'original_transaction_id',
                        $original->public_id
                    )->whereIn('status', $this->reservedStatuses())->exists()
                ) {
                    throw new InvalidArgumentException(
                        'El cargo original no está disponible para esta devolución.'
                    );
                }
            }

            // Bloquear todos los bonos en un orden estable.
            $bonuses = Bonus::whereIn('public_id', array_keys($allocations))
                ->orderBy('public_id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (Bonus $bonus) => strtolower($bonus->public_id));

            $wallet = Wallet::where(
                'public_id',
                $payment->wallet_id
            )->lockForUpdate()->firstOrFail();

            if ($wallet->currency !== $payment->currency) {
                throw new InvalidArgumentException(
                    'La moneda de la wallet no corresponde a la compra.'
                );
            }

            $now = now();

            // Validar todos los bonos antes de modificar saldos.
            foreach ($allocations as $bonusId => $amount) {
                $bonus = $bonuses->get($bonusId);

                if (
                    !$bonus ||
                    !array_key_exists($bonusId, $paidBonuses) ||
                    strtolower($bonus->beneficiary_id) !== strtolower($wallet->owner_id) ||
                    strtoupper($bonus->currency) !== strtoupper($payment->currency)
                ) {
                    throw new InvalidArgumentException(
                        'El bono no corresponde a la compra o al beneficiario.'
                    );
                }

                $reservedAmount = (int) PurchaseRefundBonus::whereIn(
                    'refund_request_id',
                    $reservedIds
                )->where('bonus_id', $bonusId)->sum('amount_cents');

                if (
                    $reservedAmount > $paidBonuses[$bonusId] ||
                    $bonus->remaining_amount_cents < 0 ||
                    $amount > $bonus->original_amount_cents -
                        $bonus->remaining_amount_cents
                ) {
                    throw new InvalidArgumentException(
                        'La devolución supera el importe permitido para el bono.'
                    );
                }

                if (
                    !in_array($bonus->status, [
                        BonusStatus::ACTIVO,
                        BonusStatus::AGOTADO,
                    ], true) ||
                    !$bonus->valid_from ||
                    !$bonus->expires_at ||
                    $now->lt($bonus->valid_from) ||
                    $now->gte($bonus->expires_at)
                ) {
                    throw new InvalidArgumentException(
                        'No se puede restaurar un bono cancelado, expirado o fuera de vigencia.'
                    );
                }
            }

            $metadata = [
                'operation' => 'PURCHASE_REFUND',
                'purchase_payment_id' => $payment->public_id,
                'refund_request_id' => $request->public_id,
                'wallet_amount_cents' => $request->wallet_amount_cents,
                'bonus_amount_cents' => $request->bonus_amount_cents,
                'executed_by' => $executedBy,
                'reviewed_by' => $request->reviewed_by,
            ];

            if ($request->wallet_amount_cents > 0) {
                $transaction = app(LedgerService::class)->credit(
                    wallet: $wallet,
                    amountCents: $request->wallet_amount_cents,
                    movementType: MovementType::DEVOLUCION,
                    idempotencyKey: $idempotencyKey,
                    referenceType: 'PURCHASE_REFUND',
                    referenceId: $request->public_id,
                    metadata: $metadata
                );
            } else {
                // Devolución solo de bonos: operación sin abono a wallet.
                $transaction = FinancialTransaction::create([
                    'public_id' => (string) Str::uuid(),
                    'idempotency_key' => $idempotencyKey,
                    'status' => TransactionStatus::PENDIENTE,
                    'reference_type' => 'PURCHASE_REFUND',
                    'reference_id' => $request->public_id,
                    'metadata' => $metadata,
                ]);
            }

            foreach ($allocations as $bonusId => $amount) {
                $bonus = $bonuses->get($bonusId);
                $bonus->remaining_amount_cents += $amount;
                $bonus->status = BonusStatus::ACTIVO;
                $bonus->save();

                BonusLedgerEntry::create([
                    'public_id' => (string) Str::uuid(),
                    'bonus_id' => $bonus->public_id,
                    'movement_type' => BonusMovementType::DEVOLUCION,
                    'amount_cents' => $amount,
                    'remaining_after_cents' => $bonus->remaining_amount_cents,
                    'reference_type' => 'PURCHASE_REFUND',
                    'reference_id' => $request->public_id,
                    'actor_id' => $executedBy,
                    'reason' => $request->reason,
                ]);
            }

            $transaction->status = TransactionStatus::COMPLETADA;
            $transaction->save();

            $request->status = RefundRequestStatus::COMPLETADA;
            $request->financial_transaction_id = $transaction->public_id;
            $request->completed_at = now();
            $request->save();

            $requestTransaction->status = TransactionStatus::COMPLETADA;
            $requestTransaction->save();

            return $transaction;
        }
    );
}

    public function approve(
    PurchaseRefundRequest $refundRequest,
    string $reviewedBy,
    ?string $reviewReason = null
): PurchaseRefundRequest {
    return $this->review(
        $refundRequest,
        $reviewedBy,
        $reviewReason,
        RefundRequestStatus::APROBADA
    );
}

public function reject(
    PurchaseRefundRequest $refundRequest,
    string $reviewedBy,
    string $reviewReason
): PurchaseRefundRequest {
    if (trim($reviewReason) === '') {
        throw new InvalidArgumentException(
            'El motivo del rechazo es obligatorio.'
        );
    }

    return $this->review(
        $refundRequest,
        $reviewedBy,
        $reviewReason,
        RefundRequestStatus::RECHAZADA
    );
}

private function review(
    PurchaseRefundRequest $refundRequest,
    string $reviewedBy,
    ?string $reviewReason,
    RefundRequestStatus $newStatus
): PurchaseRefundRequest {
    if (
        trim($reviewedBy) === '' ||
        mb_strlen($reviewedBy) > 255
    ) {
        throw new InvalidArgumentException(
            'El responsable de revisión es obligatorio y no debe superar 255 caracteres.'
        );
    }

    return DB::connection('sqlsrv')->transaction(
        function () use (
            $refundRequest,
            $reviewedBy,
            $reviewReason,
            $newStatus
        ) {
            $payment = PurchasePayment::where(
                'public_id',
                $refundRequest->purchase_payment_id
            )
                ->lockForUpdate()
                ->firstOrFail();

            $request = PurchaseRefundRequest::where(
                'public_id',
                $refundRequest->public_id
            )
                ->lockForUpdate()
                ->firstOrFail();

            if (
                strtolower($request->purchase_payment_id) !==
                strtolower($payment->public_id)
            ) {
                throw new InvalidArgumentException(
                    'La solicitud no corresponde a la compra original.'
                );
            }

            if ($request->status !== RefundRequestStatus::PENDIENTE) {
                throw new InvalidArgumentException(
                    'Solo se pueden revisar solicitudes pendientes.'
                );
            }

            $transaction = FinancialTransaction::where(
                'public_id',
                $request->request_transaction_id
            )
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $transaction->status !== TransactionStatus::PENDIENTE ||
                $transaction->reference_type !== 'PURCHASE_REFUND_REQUEST' ||
                strtolower((string) $transaction->reference_id) !==
                    strtolower($payment->public_id)
            ) {
                throw new InvalidArgumentException(
                    'La transacción de solicitud no es válida para revisarse.'
                );
            }

            $request->status = $newStatus;
            $request->reviewed_by = $reviewedBy;
            $request->review_reason = $reviewReason;
            $request->reviewed_at = now();
            $request->save();

            if ($newStatus === RefundRequestStatus::RECHAZADA) {
                $transaction->status = TransactionStatus::FALLIDA;
                $transaction->save();
            }

            return $request;
        }
    );
}

    private function normalizeBonusRefunds(array $bonusRefunds): array
    {
        $result = [];

        foreach ($bonusRefunds as $item) {
            if (
                !is_array($item) ||
                !isset($item['bonus_id'], $item['amount_cents']) ||
                !is_string($item['bonus_id']) ||
                !Str::isUuid($item['bonus_id']) ||
                !is_int($item['amount_cents']) ||
                $item['amount_cents'] <= 0
            ) {
                throw new InvalidArgumentException(
                    'Cada bono debe incluir un UUID y un importe entero positivo.'
                );
            }

            $bonusId = strtolower($item['bonus_id']);

            if (array_key_exists($bonusId, $result)) {
                throw new InvalidArgumentException(
                    'No se puede repetir un bono en el reparto de devolución.'
                );
            }

            $result[$bonusId] = $item['amount_cents'];
        }

        ksort($result);

        return $result;
    }

    private function paidBonusAmounts(PurchasePayment $payment): array
    {
        $result = [];
        $lines = $payment->bonuses()->get();

        foreach ($lines as $line) {
            $bonusId = strtolower($line->bonus_id);

            if (
                $line->amount_cents <= 0 ||
                array_key_exists($bonusId, $result)
            ) {
                throw new InvalidArgumentException(
                    'El reparto original de bonos es inconsistente.'
                );
            }

            $result[$bonusId] = $line->amount_cents;
        }

        // El pago con un solo bono no guarda líneas en la tabla múltiple.
        if ($lines->isEmpty() && $payment->bonus_amount_cents > 0) {
            if (
                !empty($payment->requested_bonus_ids) ||
                !$payment->bonus_id
            ) {
                throw new InvalidArgumentException(
                    'La compra no tiene un reparto de bonos válido.'
                );
            }

            $result[strtolower($payment->bonus_id)] =
                $payment->bonus_amount_cents;
        }

        if (
            $payment->wallet_amount_cents < 0 ||
            $payment->bonus_amount_cents < 0 ||
            $payment->total_amount_cents <= 0 ||
            array_sum($result) !== $payment->bonus_amount_cents ||
            $payment->wallet_amount_cents + $payment->bonus_amount_cents !==
                $payment->total_amount_cents
        ) {
            throw new InvalidArgumentException(
                'Los importes originales de la compra son inconsistentes.'
            );
        }

        return $result;
    }

    private function reservedStatuses(): array
    {
        return [
            RefundRequestStatus::PENDIENTE->value,
            RefundRequestStatus::APROBADA->value,
            RefundRequestStatus::COMPLETADA->value,
        ];
    }
}