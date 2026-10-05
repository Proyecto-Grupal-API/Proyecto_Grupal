<?php

use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Enums\BonusRestrictionType;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\Bonus;
use App\Domains\Financial\Models\BonusLedgerEntry;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\PurchasePayment;
use App\Domains\Financial\Models\PurchasePaymentBonus;
use App\Domains\Financial\Services\BonusService;
use App\Domains\Financial\Services\PurchasePaymentService;
use App\Domains\Financial\Services\WalletService;
use App\Domains\Financial\Enums\MovementType;

afterEach(function () {
    $payments = PurchasePayment::whereIn(
        'idempotency_key',
        [
            'test-mixed-payment',
            'test-multi-payment',
        ]
    )->get();

    foreach ($payments as $payment) {
        PurchasePaymentBonus::where(
            'purchase_payment_id',
            $payment->public_id
        )->delete();
    }
    PurchasePayment::whereIn(
        'idempotency_key',
        [
            'test-mixed-payment',
            'test-multi-payment',
        ]
    )->delete();

    $bonuses = Bonus::whereIn(
        'external_reference',
        [
            'TEST-MIXED-PAYMENT-BONUS',
            'TEST-MULTI-PAYMENT-BONUS-A',
            'TEST-MULTI-PAYMENT-BONUS-B',
        ]
    )->get();

    foreach ($bonuses as $bonus) {
        BonusLedgerEntry::where(
            'bonus_id',
            $bonus->public_id
        )->delete();

        $bonus->restrictions()->delete();
        $bonus->delete();
    }

    $transactions = FinancialTransaction::whereIn(
        'idempotency_key',
        [
            'test-mixed-payment',
            'test-multi-payment',
        ]
    )->get();

    foreach ($transactions as $transaction) {
        LedgerEntry::where(
            'transaction_id',
            $transaction->public_id
        )->delete();

        $transaction->delete();
    }

    Wallet::where(
        'owner_id',
        'mixed-payment-student'
    )->delete();
});
test('pays a purchase using bonus first and wallet for the remainder', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'mixed-payment-student',
        WalletType::USUARIO
    );

    $wallet->update([
        'available_balance_cents' => 100000,
    ]);

    $bonus = app(BonusService::class)->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'mixed-payment-student',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 50000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: false,
        allowsPartialUse: true,
        externalReference: 'TEST-MIXED-PAYMENT-BONUS'
    );

    $service = app(PurchasePaymentService::class);

    $result = $service->pay(
        wallet: $wallet,
        totalAmountCents: 80000,
        bonusPublicId: $bonus->public_id,
        idempotencyKey: 'test-mixed-payment'
    );

    $wallet->refresh();
    $bonus->refresh();

    $transaction = FinancialTransaction::where(
        'idempotency_key',
        'test-mixed-payment'
    )->first();

    $ledgerEntry = LedgerEntry::where(
        'transaction_id',
        $transaction->public_id
    )->first();

    expect($result['bonus_amount_cents'])->toBe(50000)
    ->and($result['wallet_amount_cents'])->toBe(30000)
    ->and($result['total_amount_cents'])->toBe(80000)
    ->and($bonus->remaining_amount_cents)->toBe(0)
    ->and($wallet->available_balance_cents)->toBe(70000)
    ->and($transaction)->not->toBeNull()
    ->and($ledgerEntry)->not->toBeNull()
    ->and($ledgerEntry->movement_type)->toBe(MovementType::PAGO)
    ->and($ledgerEntry->amount_cents)->toBe(-30000);
});
test('rolls back bonus consumption when wallet cannot cover the remainder', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'mixed-payment-student',
        WalletType::USUARIO
    );

    $wallet->update([
        'available_balance_cents' => 10000,
    ]);

    $bonus = app(BonusService::class)->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'mixed-payment-student',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 50000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: false,
        allowsPartialUse: true,
        externalReference: 'TEST-MIXED-PAYMENT-BONUS'
    );

    $service = app(PurchasePaymentService::class);

    expect(
        fn () => $service->pay(
            wallet: $wallet,
            totalAmountCents: 80000,
            bonusPublicId: $bonus->public_id,
            idempotencyKey: 'test-mixed-payment'
        )
    )->toThrow(
        InvalidArgumentException::class,
        'Saldo insuficiente.'
    );

    $wallet->refresh();
    $bonus->refresh();

    expect($wallet->available_balance_cents)->toBe(10000)
        ->and($bonus->remaining_amount_cents)->toBe(50000)
        ->and(
            FinancialTransaction::where(
                'idempotency_key',
                'test-mixed-payment'
            )->count()
        )->toBe(0)
        ->and(
            BonusLedgerEntry::where(
                'bonus_id',
                $bonus->public_id
            )
                ->where(
                    'movement_type',
                    \App\Domains\Financial\Enums\BonusMovementType::CONSUMO->value
                )
                ->count()
        )->toBe(0);
});
test('rejects payment when bonus and wallet belong to different owners', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'mixed-payment-student',
        WalletType::USUARIO
    );

    $wallet->update([
        'available_balance_cents' => 100000,
    ]);

    $bonus = app(BonusService::class)->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'different-student',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 50000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: false,
        allowsPartialUse: true,
        externalReference: 'TEST-MIXED-PAYMENT-BONUS'
    );

    $service = app(PurchasePaymentService::class);

    expect(
        fn () => $service->pay(
            wallet: $wallet,
            totalAmountCents: 80000,
            bonusPublicId: $bonus->public_id,
            idempotencyKey: 'test-mixed-payment'
        )
    )->toThrow(
        InvalidArgumentException::class,
        'El bono y la wallet deben pertenecer al mismo beneficiario.'
    );

    $wallet->refresh();
    $bonus->refresh();

    expect($wallet->available_balance_cents)->toBe(100000)
        ->and($bonus->remaining_amount_cents)->toBe(50000);
});
test('pays entirely with bonus when bonus covers the purchase', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'mixed-payment-student',
        WalletType::USUARIO
    );

    $wallet->update([
        'available_balance_cents' => 100000,
    ]);

    $bonus = app(BonusService::class)->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'mixed-payment-student',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 50000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: false,
        allowsPartialUse: true,
        externalReference: 'TEST-MIXED-PAYMENT-BONUS'
    );

    $service = app(PurchasePaymentService::class);

    $result = $service->pay(
        wallet: $wallet,
        totalAmountCents: 30000,
        bonusPublicId: $bonus->public_id,
        idempotencyKey: 'test-mixed-payment'
    );

    $wallet->refresh();
    $bonus->refresh();

    expect($result['total_amount_cents'])->toBe(30000)
        ->and($result['bonus_amount_cents'])->toBe(30000)
        ->and($result['wallet_amount_cents'])->toBe(0)
        ->and($bonus->remaining_amount_cents)->toBe(20000)
        ->and($wallet->available_balance_cents)->toBe(100000)
        ->and(
            FinancialTransaction::where(
                'idempotency_key',
                'test-mixed-payment'
            )->count()
        )->toBe(0);
});
test('rejects zero or negative purchase amounts', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'mixed-payment-student',
        WalletType::USUARIO
    );

    $bonus = app(BonusService::class)->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'mixed-payment-student',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 50000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: false,
        allowsPartialUse: true,
        externalReference: 'TEST-MIXED-PAYMENT-BONUS'
    );

    $service = app(PurchasePaymentService::class);

    expect(
        fn () => $service->pay(
            wallet: $wallet,
            totalAmountCents: 0,
            bonusPublicId: $bonus->public_id,
            idempotencyKey: 'test-mixed-payment'
        )
    )->toThrow(
        InvalidArgumentException::class,
        'El monto total de la compra debe ser mayor que cero.'
    );

    expect(
        fn () => $service->pay(
            wallet: $wallet,
            totalAmountCents: -10000,
            bonusPublicId: $bonus->public_id,
            idempotencyKey: 'test-mixed-payment'
        )
    )->toThrow(
        InvalidArgumentException::class,
        'El monto total de la compra debe ser mayor que cero.'
    );

    $wallet->refresh();
    $bonus->refresh();

    expect($wallet->available_balance_cents)->toBe(0)
        ->and($bonus->remaining_amount_cents)->toBe(50000)
        ->and(
            FinancialTransaction::where(
                'idempotency_key',
                'test-mixed-payment'
            )->count()
        )->toBe(0);
});
test('pays with a bonus when business restriction matches', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'mixed-payment-student',
        WalletType::USUARIO
    );

    $wallet->update([
        'available_balance_cents' => 100000,
    ]);

    $bonusService = app(BonusService::class);

    $bonus = $bonusService->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'mixed-payment-student',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::NEGOCIO,
        amountCents: 50000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: false,
        allowsPartialUse: true,
        externalReference: 'TEST-MIXED-PAYMENT-BONUS'
    );

    $bonusService->addRestriction(
        bonusPublicId: $bonus->public_id,
        restrictionType: BonusRestrictionType::NEGOCIO,
        targetId: 'business-001'
    );

    $service = app(PurchasePaymentService::class);

    $result = $service->pay(
        wallet: $wallet,
        totalAmountCents: 80000,
        bonusPublicId: $bonus->public_id,
        idempotencyKey: 'test-mixed-payment',
        businessId: 'business-001'
    );

    $wallet->refresh();
    $bonus->refresh();

    expect($result['bonus_amount_cents'])->toBe(50000)
        ->and($result['wallet_amount_cents'])->toBe(30000)
        ->and($bonus->remaining_amount_cents)->toBe(0)
        ->and($wallet->available_balance_cents)->toBe(70000);
});
test('rejects payment when business restriction does not match', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'mixed-payment-student',
        WalletType::USUARIO
    );

    $wallet->update([
        'available_balance_cents' => 100000,
    ]);

    $bonusService = app(BonusService::class);

    $bonus = $bonusService->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'mixed-payment-student',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::NEGOCIO,
        amountCents: 50000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: false,
        allowsPartialUse: true,
        externalReference: 'TEST-MIXED-PAYMENT-BONUS'
    );

    $bonusService->addRestriction(
        bonusPublicId: $bonus->public_id,
        restrictionType: BonusRestrictionType::NEGOCIO,
        targetId: 'business-001'
    );

    $service = app(PurchasePaymentService::class);

    expect(
        fn () => $service->pay(
            wallet: $wallet,
            totalAmountCents: 80000,
            bonusPublicId: $bonus->public_id,
            idempotencyKey: 'test-mixed-payment',
            businessId: 'business-999'
        )
    )->toThrow(InvalidArgumentException::class);

    $wallet->refresh();
    $bonus->refresh();

    expect($wallet->available_balance_cents)->toBe(100000)
        ->and($bonus->remaining_amount_cents)->toBe(50000)
        ->and(
            FinancialTransaction::where(
                'idempotency_key',
                'test-mixed-payment'
            )->count()
        )->toBe(0);
});
test('rejects payment when bonus and wallet have different currencies', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'mixed-payment-student',
        WalletType::USUARIO
    );

    $wallet->update([
        'currency' => 'USD',
        'available_balance_cents' => 100000,
    ]);

    $bonus = app(BonusService::class)->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'mixed-payment-student',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 50000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: false,
        allowsPartialUse: true,
        externalReference: 'TEST-MIXED-PAYMENT-BONUS'
    );

    $service = app(PurchasePaymentService::class);

    expect(
        fn () => $service->pay(
            wallet: $wallet,
            totalAmountCents: 80000,
            bonusPublicId: $bonus->public_id,
            idempotencyKey: 'test-mixed-payment'
        )
    )->toThrow(
        InvalidArgumentException::class,
        'El bono y la wallet deben utilizar la misma moneda.'
    );

    $wallet->refresh();
    $bonus->refresh();

    expect($wallet->available_balance_cents)->toBe(100000)
        ->and($bonus->remaining_amount_cents)->toBe(50000)
        ->and(
            FinancialTransaction::where(
                'idempotency_key',
                'test-mixed-payment'
            )->count()
        )->toBe(0);
});
test('does not consume payment twice when request is retried', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'mixed-payment-student',
        WalletType::USUARIO
    );

    $wallet->update([
        'available_balance_cents' => 100000,
    ]);

    $bonus = app(BonusService::class)->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'mixed-payment-student',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 50000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: false,
        allowsPartialUse: true,
        externalReference: 'TEST-MIXED-PAYMENT-BONUS'
    );

    $service = app(PurchasePaymentService::class);

    $firstResult = $service->pay(
        wallet: $wallet,
        totalAmountCents: 80000,
        bonusPublicId: $bonus->public_id,
        idempotencyKey: 'test-mixed-payment'
    );

    $secondResult = $service->pay(
        wallet: $wallet,
        totalAmountCents: 80000,
        bonusPublicId: $bonus->public_id,
        idempotencyKey: 'test-mixed-payment'
    );

    $wallet->refresh();
    $bonus->refresh();

    expect($secondResult)->toBe($firstResult)
        ->and($bonus->remaining_amount_cents)->toBe(0)
        ->and($wallet->available_balance_cents)->toBe(70000)
        ->and(
            FinancialTransaction::where(
                'idempotency_key',
                'test-mixed-payment'
            )->count()
        )->toBe(1);
});
test('rejects idempotency key reuse with different payment data', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'mixed-payment-student',
        WalletType::USUARIO
    );

    $wallet->update([
        'available_balance_cents' => 100000,
    ]);

    $bonus = app(BonusService::class)->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'mixed-payment-student',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 50000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: false,
        allowsPartialUse: true,
        externalReference: 'TEST-MIXED-PAYMENT-BONUS'
    );

    $service = app(PurchasePaymentService::class);

    $service->pay(
        wallet: $wallet,
        totalAmountCents: 80000,
        bonusPublicId: $bonus->public_id,
        idempotencyKey: 'test-mixed-payment'
    );

    expect(
        fn () => $service->pay(
            wallet: $wallet,
            totalAmountCents: 90000,
            bonusPublicId: $bonus->public_id,
            idempotencyKey: 'test-mixed-payment'
        )
    )->toThrow(
        InvalidArgumentException::class,
        'La clave de idempotencia ya fue utilizada con datos diferentes.'
    );

    $wallet->refresh();
    $bonus->refresh();

    expect($wallet->available_balance_cents)->toBe(70000)
        ->and($bonus->remaining_amount_cents)->toBe(0)
        ->and(
            PurchasePayment::where(
                'idempotency_key',
                'test-mixed-payment'
            )->count()
        )->toBe(1);
});
test('does not consume bonus twice when bonus covers the entire retried payment', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'mixed-payment-student',
        WalletType::USUARIO
    );

    $wallet->update([
        'available_balance_cents' => 100000,
    ]);

    $bonus = app(BonusService::class)->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'mixed-payment-student',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 50000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: false,
        allowsPartialUse: true,
        externalReference: 'TEST-MIXED-PAYMENT-BONUS'
    );

    $service = app(PurchasePaymentService::class);

    $firstResult = $service->pay(
        wallet: $wallet,
        totalAmountCents: 30000,
        bonusPublicId: $bonus->public_id,
        idempotencyKey: 'test-mixed-payment'
    );

    $secondResult = $service->pay(
        wallet: $wallet,
        totalAmountCents: 30000,
        bonusPublicId: $bonus->public_id,
        idempotencyKey: 'test-mixed-payment'
    );

    $wallet->refresh();
    $bonus->refresh();

    expect($secondResult)->toBe($firstResult)
        ->and($bonus->remaining_amount_cents)->toBe(20000)
        ->and($wallet->available_balance_cents)->toBe(100000)
        ->and(
            PurchasePayment::where(
                'idempotency_key',
                'test-mixed-payment'
            )->count()
        )->toBe(1)
        ->and(
            FinancialTransaction::where(
                'idempotency_key',
                'test-mixed-payment'
            )->count()
        )->toBe(0);
});
test('rejects idempotency key reuse with a different business', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'mixed-payment-student',
        WalletType::USUARIO
    );

    $wallet->update([
        'available_balance_cents' => 100000,
    ]);

    $bonus = app(BonusService::class)->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'mixed-payment-student',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 50000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: false,
        allowsPartialUse: true,
        externalReference: 'TEST-MIXED-PAYMENT-BONUS'
    );

    $service = app(PurchasePaymentService::class);

    $service->pay(
        wallet: $wallet,
        totalAmountCents: 80000,
        bonusPublicId: $bonus->public_id,
        idempotencyKey: 'test-mixed-payment',
        businessId: 'business-001'
    );

    expect(fn () => $service->pay(
        wallet: $wallet,
        totalAmountCents: 80000,
        bonusPublicId: $bonus->public_id,
        idempotencyKey: 'test-mixed-payment',
        businessId: 'business-002'
    ))->toThrow(
        InvalidArgumentException::class,
        'La clave de idempotencia ya fue utilizada con datos diferentes.'
    );

    $wallet->refresh();
    $bonus->refresh();

    expect($wallet->available_balance_cents)->toBe(70000)
        ->and($bonus->remaining_amount_cents)->toBe(0)
        ->and(
            PurchasePayment::where(
                'idempotency_key',
                'test-mixed-payment'
            )->count()
        )->toBe(1)
        ->and(
            FinancialTransaction::where(
                'idempotency_key',
                'test-mixed-payment'
            )->count()
        )->toBe(1);
});
test('rejects idempotency key reuse with a different category', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'mixed-payment-student',
        WalletType::USUARIO
    );

    $wallet->update([
        'available_balance_cents' => 100000,
    ]);

    $bonus = app(BonusService::class)->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'mixed-payment-student',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 50000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: false,
        allowsPartialUse: true,
        externalReference: 'TEST-MIXED-PAYMENT-BONUS'
    );

    $service = app(PurchasePaymentService::class);

    $service->pay(
        wallet: $wallet,
        totalAmountCents: 80000,
        bonusPublicId: $bonus->public_id,
        idempotencyKey: 'test-mixed-payment',
        categoryId: 'category-001'
    );

    expect(fn () => $service->pay(
        wallet: $wallet,
        totalAmountCents: 80000,
        bonusPublicId: $bonus->public_id,
        idempotencyKey: 'test-mixed-payment',
        categoryId: 'category-002'
    ))->toThrow(
        InvalidArgumentException::class,
        'La clave de idempotencia ya fue utilizada con datos diferentes.'
    );

    $wallet->refresh();
    $bonus->refresh();

    expect($wallet->available_balance_cents)->toBe(70000)
        ->and($bonus->remaining_amount_cents)->toBe(0)
        ->and(
            PurchasePayment::where(
                'idempotency_key',
                'test-mixed-payment'
            )->count()
        )->toBe(1)
        ->and(
            FinancialTransaction::where(
                'idempotency_key',
                'test-mixed-payment'
            )->count()
        )->toBe(1);
});
test('pays a purchase using multiple bonuses before wallet', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'mixed-payment-student',
        WalletType::USUARIO
    );

    $wallet->update([
        'available_balance_cents' => 100000,
    ]);

    $bonusService = app(BonusService::class);

    $bonusA = $bonusService->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'mixed-payment-student',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 30000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: true,
        allowsPartialUse: true,
        externalReference: 'TEST-MULTI-PAYMENT-BONUS-A'
    );

    $bonusB = $bonusService->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'mixed-payment-student',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 20000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: true,
        allowsPartialUse: true,
        externalReference: 'TEST-MULTI-PAYMENT-BONUS-B'
    );

    $result = app(PurchasePaymentService::class)
        ->payWithMultipleBonuses(
            wallet: $wallet,
            totalAmountCents: 80000,
            bonusPublicIds: [
                $bonusA->public_id,
                $bonusB->public_id,
            ],
            idempotencyKey: 'test-multi-payment'
        );

    expect($result['consumptions'])->toHaveCount(2)
        ->and($result['consumptions'][0]['amount_cents'])->toBe(30000)
        ->and($result['consumptions'][1]['amount_cents'])->toBe(20000)
        ->and($result['wallet_amount_cents'])->toBe(30000);

    $bonusA->refresh();
    $bonusB->refresh();
    $wallet->refresh();

    $payment = PurchasePayment::where(
        'idempotency_key',
        'test-multi-payment'
    )->first();

    expect($payment)->not->toBeNull()
        ->and($payment->total_amount_cents)->toBe(80000)
        ->and($payment->bonus_amount_cents)->toBe(50000)
        ->and($payment->wallet_amount_cents)->toBe(30000)
        ->and($payment->bonuses()->count())->toBe(2);

   expect($bonusA->remaining_amount_cents)->toBe(0)
       ->and($bonusB->remaining_amount_cents)->toBe(0)
       ->and($wallet->available_balance_cents)->toBe(70000)
       ->and(
           FinancialTransaction::where(
               'idempotency_key',
               'test-multi-payment'
           )->count()
       )->toBe(1);
});
test('rolls back multiple bonuses when wallet cannot cover remainder', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'mixed-payment-student',
        WalletType::USUARIO
    );

    $wallet->update([
        'available_balance_cents' => 10000,
    ]);

    $bonusService = app(BonusService::class);

    $bonusA = $bonusService->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'mixed-payment-student',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 30000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: true,
        allowsPartialUse: true,
        externalReference: 'TEST-MULTI-PAYMENT-BONUS-A'
    );

    $bonusB = $bonusService->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'mixed-payment-student',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 20000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: true,
        allowsPartialUse: true,
        externalReference: 'TEST-MULTI-PAYMENT-BONUS-B'
    );

    expect(fn () =>
        app(PurchasePaymentService::class)
            ->payWithMultipleBonuses(
                wallet: $wallet,
                totalAmountCents: 80000,
                bonusPublicIds: [
                    $bonusA->public_id,
                    $bonusB->public_id,
                ],
                idempotencyKey: 'test-multi-payment'
            )
    )->toThrow(InvalidArgumentException::class);

    $bonusA->refresh();
    $bonusB->refresh();
    $wallet->refresh();

    expect($bonusA->remaining_amount_cents)->toBe(30000)
        ->and($bonusB->remaining_amount_cents)->toBe(20000)
        ->and($wallet->available_balance_cents)->toBe(10000)
        ->and(
            FinancialTransaction::where(
                'idempotency_key',
                'test-multi-payment'
            )->count()
        )->toBe(0)
        ->and(
              PurchasePayment::where(
                  'idempotency_key',
                  'test-multi-payment'
              )->count()
        )->toBe(0)
        ->and(
            PurchasePaymentBonus::count()
        )->toBe(0);
});
test('does not consume multiple bonuses or wallet twice when request is retried', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'mixed-payment-student',
        WalletType::USUARIO
    );

    $wallet->update([
        'available_balance_cents' => 100000,
    ]);

    $bonusService = app(BonusService::class);

    $bonusA = $bonusService->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'mixed-payment-student',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 30000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: true,
        allowsPartialUse: true,
        externalReference: 'TEST-MULTI-PAYMENT-BONUS-A'
    );

    $bonusB = $bonusService->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'mixed-payment-student',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 20000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: true,
        allowsPartialUse: true,
        externalReference: 'TEST-MULTI-PAYMENT-BONUS-B'
    );

    $service = app(PurchasePaymentService::class);

    $firstResult = $service->payWithMultipleBonuses(
        wallet: $wallet,
        totalAmountCents: 80000,
        bonusPublicIds: [
            $bonusA->public_id,
            $bonusB->public_id,
        ],
        idempotencyKey: 'test-multi-payment'
    );

    $secondResult = $service->payWithMultipleBonuses(
        wallet: $wallet,
        totalAmountCents: 80000,
        bonusPublicIds: [
            $bonusA->public_id,
            $bonusB->public_id,
        ],
        idempotencyKey: 'test-multi-payment'
    );

    $bonusA->refresh();
    $bonusB->refresh();
    $wallet->refresh();

    expect($secondResult)->toBe($firstResult)
        ->and($bonusA->remaining_amount_cents)->toBe(0)
        ->and($bonusB->remaining_amount_cents)->toBe(0)
        ->and($wallet->available_balance_cents)->toBe(70000)
        ->and(
            PurchasePayment::where(
                'idempotency_key',
                'test-multi-payment'
            )->count()
        )->toBe(1)
        ->and(
            FinancialTransaction::where(
                'idempotency_key',
                'test-multi-payment'
            )->count()
        )->toBe(1)
        ->and(PurchasePaymentBonus::count())->toBe(2);
});
test('rejects multiple bonus idempotency key reuse with different payment data', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'mixed-payment-student',
        WalletType::USUARIO
    );

    $wallet->update([
        'available_balance_cents' => 100000,
    ]);

    $bonusService = app(BonusService::class);

    $bonusA = $bonusService->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'mixed-payment-student',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 30000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: true,
        allowsPartialUse: true,
        externalReference: 'TEST-MULTI-PAYMENT-BONUS-A'
    );

    $bonusB = $bonusService->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'mixed-payment-student',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 20000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: true,
        allowsPartialUse: true,
        externalReference: 'TEST-MULTI-PAYMENT-BONUS-B'
    );

    $service = app(PurchasePaymentService::class);

    $service->payWithMultipleBonuses(
        wallet: $wallet,
        totalAmountCents: 80000,
        bonusPublicIds: [
            $bonusA->public_id,
            $bonusB->public_id,
        ],
        idempotencyKey: 'test-multi-payment'
    );

    expect(fn () =>
        $service->payWithMultipleBonuses(
            wallet: $wallet,
            totalAmountCents: 90000,
            bonusPublicIds: [
                $bonusA->public_id,
                $bonusB->public_id,
            ],
            idempotencyKey: 'test-multi-payment'
        )
    )->toThrow(
        InvalidArgumentException::class,
        'La clave de idempotencia ya fue utilizada con datos diferentes.'
    );

    $bonusA->refresh();
    $bonusB->refresh();
    $wallet->refresh();

    expect($bonusA->remaining_amount_cents)->toBe(0)
        ->and($bonusB->remaining_amount_cents)->toBe(0)
        ->and($wallet->available_balance_cents)->toBe(70000)
        ->and(
            PurchasePayment::where(
                'idempotency_key',
                'test-multi-payment'
            )->count()
        )->toBe(1)
        ->and(PurchasePaymentBonus::count())->toBe(2)
        ->and(
            FinancialTransaction::where(
                'idempotency_key',
                'test-multi-payment'
            )->count()
        )->toBe(1);
});