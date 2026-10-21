<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Models\PurchasePayment;
use App\Domains\Financial\Models\PurchasePaymentBonus;
use App\Domains\Financial\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PurchasePaymentService
{
    public function __construct(
        private BonusService $bonusService,
        private LedgerService $ledgerService
    ) {
    }

    public function pay(
        Wallet $wallet,
        int $totalAmountCents,
        string $bonusPublicId,
        string $idempotencyKey,
        ?string $businessId = null,
        ?string $categoryId = null,
        ?array $marketplace = null
    ): array {
        if ($totalAmountCents <= 0) {
            throw new InvalidArgumentException(
                'El monto total de la compra debe ser mayor que cero.'
            );
        }

        return DB::connection('sqlsrv')->transaction(
            function () use (
                $wallet,
                $totalAmountCents,
                $bonusPublicId,
                $idempotencyKey,
                $businessId,
                $categoryId,
                $marketplace
            ): array {
                $existingPayment = PurchasePayment::where(
                    'idempotency_key',
                    $idempotencyKey
                )->lockForUpdate()->first();

               if ($existingPayment) {
                $sameRequest =
                      strtolower((string) $existingPayment->wallet_id)
                          === strtolower((string) $wallet->public_id)
                      && strtolower((string) $existingPayment->bonus_id)
                          === strtolower((string) $bonusPublicId)
                      && (int) $existingPayment->total_amount_cents
                          === $totalAmountCents
                      && $existingPayment->business_id === $businessId
                      && $existingPayment->category_id === $categoryId;

                   if (!$sameRequest) {
                       throw new InvalidArgumentException(
                           'La clave de idempotencia ya fue utilizada con datos diferentes.'
                       );
                   }

                   app(\App\Domains\Financial\Integrations\Team4\PurchasePaymentEvents::class)->paid($existingPayment, $marketplace, true);
                   return [
                       'total_amount_cents' =>
                           $existingPayment->total_amount_cents,
                       'bonus_amount_cents' =>
                           $existingPayment->bonus_amount_cents,
                       'wallet_amount_cents' =>
                           $existingPayment->wallet_amount_cents,
                  ];
               }

                $bonus = $this->bonusService->expireIfNeeded(
                    $bonusPublicId
                );

                if (
                    strtolower($bonus->beneficiary_id)
                    !== strtolower($wallet->owner_id)
                ) {
                    throw new InvalidArgumentException(
                        'El bono y la wallet deben pertenecer al mismo beneficiario.'
                    );
                }

                if (
                    strtoupper($bonus->currency)
                    !== strtoupper($wallet->currency)
                ) {
                    throw new InvalidArgumentException(
                        'El bono y la wallet deben utilizar la misma moneda.'
                    );
                }

                $bonusAmountCents = min(
                    $bonus->remaining_amount_cents,
                    $totalAmountCents
                );

                $walletAmountCents =
                    $totalAmountCents - $bonusAmountCents;

                if ($bonusAmountCents > 0) {
                    $this->bonusService->consume(
                        bonusPublicId: $bonusPublicId,
                        amountCents: $bonusAmountCents,
                        businessId: $businessId,
                        categoryId: $categoryId
                    );
                }

                if ($walletAmountCents > 0) {
                    $this->ledgerService->debit(
                        wallet: $wallet,
                        amountCents: $walletAmountCents,
                        movementType: MovementType::PAGO,
                        idempotencyKey: $idempotencyKey,
                        referenceType: 'PURCHASE',
                        referenceId: $bonusPublicId,
                        metadata: [
                            'total_amount_cents' =>
                                $totalAmountCents,
                            'bonus_amount_cents' =>
                                $bonusAmountCents,
                            'wallet_amount_cents' =>
                                $walletAmountCents,
                        ]
                    );
                }

                $payment = PurchasePayment::create([
                    'public_id' => (string) Str::uuid(),
                    'idempotency_key' => $idempotencyKey,
                    'wallet_id' => $wallet->public_id,
                    'bonus_id' => $bonus->public_id,
                    'business_id' => $businessId,
                    'category_id' => $categoryId,
                    'currency' => strtoupper($wallet->currency),
                    'total_amount_cents' => $totalAmountCents,
                    'bonus_amount_cents' => $bonusAmountCents,
                    'wallet_amount_cents' => $walletAmountCents,
                    'status' => 'COMPLETADO',
                ]);

                app(\App\Domains\Financial\Integrations\Team4\PurchasePaymentEvents::class)->paid($payment, $marketplace);
                return [
                    'total_amount_cents' => $totalAmountCents,
                    'bonus_amount_cents' => $bonusAmountCents,
                    'wallet_amount_cents' => $walletAmountCents,
                ];
            }
        );
    }
public function payWithMultipleBonuses(
    Wallet $wallet,
    int $totalAmountCents,
    array $bonusPublicIds,
    string $idempotencyKey,
    ?string $businessId = null,
    ?string $categoryId = null,
        ?array $marketplace = null
): array {
    if ($totalAmountCents <= 0) {
        throw new InvalidArgumentException(
            'El monto total de la compra debe ser mayor que cero.'
        );
    }

    if (count($bonusPublicIds) < 2) {
        throw new InvalidArgumentException(
            'El pago combinado requiere al menos dos bonos.'
        );
    }

    $normalizedBonusIds = collect($bonusPublicIds)
        ->map(fn ($id) => strtolower((string) $id));

    if ($normalizedBonusIds->unique()->count() !== $normalizedBonusIds->count()) {
        throw new InvalidArgumentException(
            'No se puede utilizar el mismo bono más de una vez en un pago combinado.'
       );
    }

    return DB::connection('sqlsrv')->transaction(
        function () use (
            $wallet,
            $totalAmountCents,
            $bonusPublicIds,
            $idempotencyKey,
            $businessId,
            $categoryId,
            $marketplace
        ): array {
            $existingPayment = PurchasePayment::where(
                'idempotency_key',
                $idempotencyKey
            )->lockForUpdate()->first();

            if ($existingPayment) {
                $existingBonusIds = collect(
                    $existingPayment->requested_bonus_ids ?? []
                )
                    ->map(
                        fn ($id) =>
                            strtolower((string) $id)
                    )
                    ->sort()
                    ->values()
                    ->all();

                $requestedBonusIds = collect($bonusPublicIds)
                    ->map(
                        fn ($id) =>
                            strtolower((string) $id)
                    )
                    ->sort()
                    ->values()
                    ->all();

                $sameRequest =
                    strtolower(
                        (string) $existingPayment->wallet_id
                    ) === strtolower(
                        (string) $wallet->public_id
                    )
                    && (int) $existingPayment->total_amount_cents
                        === $totalAmountCents
                    && $existingPayment->business_id
                        === $businessId
                    && $existingPayment->category_id
                        === $categoryId
                    && $existingBonusIds
                        === $requestedBonusIds;

                if (!$sameRequest) {
                    throw new InvalidArgumentException(
                        'La clave de idempotencia ya fue utilizada con datos diferentes.'
                    );
                }

                app(\App\Domains\Financial\Integrations\Team4\PurchasePaymentEvents::class)->paid($existingPayment, $marketplace, true);
                return [
                    'consumptions' => $existingPayment
                        ->bonuses()
                        ->get()
                        ->map(fn ($bonus) => [
                            'bonus_id' => $bonus->bonus_id,
                            'amount_cents' =>
                                $bonus->amount_cents,
                        ])
                        ->all(),
                    'wallet_amount_cents' =>
                        (int) $existingPayment
                            ->wallet_amount_cents,
                ];
            }

            $remainingAmountCents = $totalAmountCents;
            $consumptions = [];

            foreach ($bonusPublicIds as $bonusPublicId) {
                $bonus = $this->bonusService->expireIfNeeded(
                    $bonusPublicId
                );

                if (
                    strtolower($bonus->beneficiary_id)
                    !== strtolower($wallet->owner_id)
                ) {
                    throw new InvalidArgumentException(
                        'Todos los bonos y la wallet deben pertenecer al mismo beneficiario.'
                    );
                }

                if (
                    strtoupper($bonus->currency)
                    !== strtoupper($wallet->currency)
                ) {
                    throw new InvalidArgumentException(
                        'Todos los bonos y la wallet deben utilizar la misma moneda.'
                    );
                }

                $amountCents = min(
                    $bonus->remaining_amount_cents,
                    $remainingAmountCents
                );

                if ($amountCents > 0) {
                    $consumptions[] = [
                        'bonus_id' => $bonus->public_id,
                        'amount_cents' => $amountCents,
                    ];

                    $remainingAmountCents -= $amountCents;
                }

                if ($remainingAmountCents === 0) {
                    break;
                }
            }

            if (count($consumptions) < 2) {
                throw new InvalidArgumentException(
                    'El pago combinado debe utilizar al menos dos bonos.'
                );
            }

            $this->bonusService->consumeMultiple(
                consumptions: $consumptions,
                businessId: $businessId,
                categoryId: $categoryId
            );

            $payment = PurchasePayment::create([
                'public_id' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'wallet_id' => $wallet->public_id,
                'bonus_id' => $consumptions[0]['bonus_id'],
                'requested_bonus_ids' => collect($bonusPublicIds)
                    ->map(fn ($id) => strtolower((string) $id))
                    ->sort()
                    ->values()
                    ->all(),
                'business_id' => $businessId,
                'category_id' => $categoryId,
                'currency' => strtoupper($wallet->currency),
                'total_amount_cents' => $totalAmountCents,
                'bonus_amount_cents' =>
                    $totalAmountCents
                    - $remainingAmountCents,
                'wallet_amount_cents' =>
                    $remainingAmountCents,
                'status' => 'COMPLETADO',
            ]);

            foreach ($consumptions as $consumption) {
                PurchasePaymentBonus::create([
                    'purchase_payment_id' =>
                        $payment->public_id,
                    'bonus_id' =>
                        $consumption['bonus_id'],
                    'amount_cents' =>
                        $consumption['amount_cents'],
                ]);
            }

            if ($remainingAmountCents > 0) {
                $this->ledgerService->debit(
                    wallet: $wallet,
                    amountCents: $remainingAmountCents,
                    movementType: MovementType::PAGO,
                    idempotencyKey: $idempotencyKey,
                    referenceType: 'PURCHASE',
                    referenceId: null,
                    metadata: [
                        'total_amount_cents' =>
                            $totalAmountCents,
                        'bonus_amount_cents' =>
                            $totalAmountCents
                            - $remainingAmountCents,
                        'wallet_amount_cents' =>
                            $remainingAmountCents,
                    ]
                );
            }

            app(\App\Domains\Financial\Integrations\Team4\PurchasePaymentEvents::class)->paid($payment, $marketplace);
            return [
                'consumptions' => $consumptions,
                'wallet_amount_cents' =>
                    $remainingAmountCents,
            ];
        }
    );
}

    /** Internal application operation. An HTTP caller must verify payer authorization separately. */
    public function payWithWallet(
        Wallet $wallet,
        int $totalAmountCents,
        string $idempotencyKey,
        ?string $businessId = null,
        ?string $categoryId = null,
        ?array $marketplace = null
    ): array {
        if ($totalAmountCents <= 0 || $totalAmountCents > 9007199254740991
            || trim($idempotencyKey) === '' || strlen($idempotencyKey) > 255) {
            throw new InvalidArgumentException('Importe positivo entero y clave de idempotencia valida requeridos.');
        }
        try {
        return DB::connection('sqlsrv')->transaction(function () use (
            $wallet, $totalAmountCents, $idempotencyKey, $businessId, $categoryId, $marketplace
        ): array {
            $lockedWallet = Wallet::where('public_id', $wallet->public_id)->lockForUpdate()->firstOrFail();
            if ($lockedWallet->owner_type !== 'USER'
                || $lockedWallet->type !== \App\Domains\Financial\Enums\WalletType::USUARIO
                || trim((string)$lockedWallet->owner_id) === '') {
                throw new InvalidArgumentException('La compra requiere una wallet de usuario.');
            }
            $payment = PurchasePayment::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($payment) {
                if (strtolower($payment->wallet_id) !== strtolower($lockedWallet->public_id)
                    || $payment->bonus_id !== null || $payment->bonus_amount_cents !== 0
                    || !empty($payment->requested_bonus_ids) || $payment->bonuses()->exists()
                    || $payment->total_amount_cents !== $totalAmountCents || $payment->wallet_amount_cents !== $totalAmountCents
                    || $payment->business_id !== $businessId || $payment->category_id !== $categoryId
                    || $payment->currency !== strtoupper($lockedWallet->currency) || $payment->status !== 'COMPLETADO') {
                    throw new InvalidArgumentException('La clave ya pertenece a una compra diferente.');
                }
                $transaction = \App\Domains\Financial\Models\FinancialTransaction::where('idempotency_key', $idempotencyKey)->first();
                $entries = $transaction ? \App\Domains\Financial\Models\LedgerEntry::where('transaction_id', $transaction->public_id)->get() : collect();
                $entry = $entries->first();
                if (!$transaction || $transaction->status !== \App\Domains\Financial\Enums\TransactionStatus::COMPLETADA
                    || $transaction->reference_type !== 'PURCHASE' || strtolower((string)$transaction->reference_id) !== strtolower($payment->public_id)
                    || $entries->count() !== 1 || !$entry || strtolower($entry->wallet_id) !== strtolower($lockedWallet->public_id)
                    || $entry->movement_type !== MovementType::PAGO || $entry->amount_cents !== -$totalAmountCents) {
                    throw new InvalidArgumentException('La compra no tiene evidencia contable consistente.');
                }
                app(\App\Domains\Financial\Integrations\Team4\PurchasePaymentEvents::class)->paid($payment, $marketplace, true);
            } else {
                if ($lockedWallet->status !== \App\Domains\Financial\Enums\WalletStatus::ACTIVA) {
                    throw new InvalidArgumentException('La wallet no esta activa.');
                }
                if (\App\Domains\Financial\Models\FinancialTransaction::where('idempotency_key', $idempotencyKey)->exists()) {
                    throw new InvalidArgumentException('La clave ya pertenece a otra operacion financiera.');
                }
                $evaluation = app(FinancialLimitService::class)->evaluate($lockedWallet, MovementType::PAGO, $totalAmountCents,
                    actions: [\App\Domains\Financial\Enums\LimitAction::BLOQUEAR]);
                if ($evaluation->isBlocked()) {
                    throw new \App\Domains\Financial\Exceptions\FinancialLimitExceededException($evaluation);
                }
                $payment = PurchasePayment::create([
                    'public_id'=>(string)Str::uuid(), 'idempotency_key'=>$idempotencyKey,
                    'wallet_id'=>$lockedWallet->public_id, 'bonus_id'=>null, 'requested_bonus_ids'=>null,
                    'business_id'=>$businessId, 'category_id'=>$categoryId, 'currency'=>strtoupper($lockedWallet->currency),
                    'total_amount_cents'=>$totalAmountCents, 'bonus_amount_cents'=>0,
                    'wallet_amount_cents'=>$totalAmountCents, 'status'=>'COMPLETADO',
                ]);
                $this->ledgerService->debit(
                    wallet:$lockedWallet, amountCents:$totalAmountCents, movementType:MovementType::PAGO,
                    idempotencyKey:$idempotencyKey, referenceType:'PURCHASE', referenceId:$payment->public_id,
                    metadata:['purchase_payment_id'=>strtolower($payment->public_id),'total_amount_cents'=>$totalAmountCents,
                        'bonus_amount_cents'=>0,'wallet_amount_cents'=>$totalAmountCents]
                );
                app(\App\Domains\Financial\Integrations\Team4\PurchasePaymentEvents::class)->paid($payment, $marketplace);
            }
            return ['purchase_payment_id'=>strtolower($payment->public_id),
                'total_amount_cents'=>$payment->total_amount_cents,'bonus_amount_cents'=>0,
                'wallet_amount_cents'=>$payment->wallet_amount_cents];
        });
        } catch (\App\Domains\Financial\Exceptions\FinancialLimitExceededException $exception) {
            // Record rejected-attempt evidence after the operation transaction rolled back. No actor is fabricated.
            $alert = app(TransactionAlertService::class)->recordBlocked($wallet, $exception->evaluation, 'PURCHASE_WALLET_ATTEMPT');
            throw new \App\Domains\Financial\Exceptions\FinancialLimitExceededException(
                $exception->evaluation, $alert->public_id, $alert->correlation_id
            );
        }
    }
}
