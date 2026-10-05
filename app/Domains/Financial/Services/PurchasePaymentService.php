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
        ?string $categoryId = null
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
                $categoryId
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

                PurchasePayment::create([
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
    ?string $categoryId = null
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

    return DB::connection('sqlsrv')->transaction(
        function () use (
            $wallet,
            $totalAmountCents,
            $bonusPublicIds,
            $idempotencyKey,
            $businessId,
            $categoryId
        ): array {
            $existingPayment = PurchasePayment::where(
                'idempotency_key',
                $idempotencyKey
            )->lockForUpdate()->first();

            if ($existingPayment) {
                $existingBonusIds = $existingPayment
                    ->bonuses()
                    ->pluck('bonus_id')
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

            return [
                'consumptions' => $consumptions,
                'wallet_amount_cents' =>
                    $remainingAmountCents,
            ];
        }
    );
}
}
