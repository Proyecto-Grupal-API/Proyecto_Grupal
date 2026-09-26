<?php

use App\Domains\Financial\Enums\TopUpMethod;
use App\Domains\Financial\Enums\TopUpStatus;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\TopUp;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\TopUpService;
use App\Domains\Financial\Services\WalletService;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Models\FinancialTransaction;

afterEach(function () {
    $wallet = Wallet::where(
        'owner_id',
        'test-topup-user'
    )->first();

    if ($wallet) {
        $transactionIds = FinancialTransaction::where(
            'reference_type',
            'TOPUP'
        )
            ->whereIn(
                'reference_id',
                TopUp::where(
                    'wallet_id',
                    $wallet->public_id
                )->pluck('public_id')
            )
            ->pluck('public_id');

        LedgerEntry::whereIn(
            'transaction_id',
            $transactionIds
        )->delete();

        FinancialTransaction::whereIn(
            'public_id',
            $transactionIds
        )->delete();

        TopUp::where(
            'wallet_id',
            $wallet->public_id
        )->delete();

        $wallet->delete();
    }
});
test('creates a pending top up without changing the wallet balance', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'test-topup-user',
        WalletType::USUARIO
    );

    $service = app(TopUpService::class);

    $topUp = $service->create(
        $wallet,
        10050,
        TopUpMethod::EFECTIVO,
        'test-agent-001',
        'test-reference-001'
    );

    $wallet->refresh();

    expect($topUp->public_id)->not->toBeEmpty()
        ->and(strtolower($topUp->wallet_id))
        ->toBe(strtolower($wallet->public_id))
        ->and($topUp->amount_cents)->toBe(10050)
        ->and($topUp->currency)->toBe('MXN')
        ->and($topUp->method)->toBe(TopUpMethod::EFECTIVO)
        ->and($topUp->status)->toBe(TopUpStatus::PENDIENTE)
        ->and($topUp->folio)->toBeNull()
        ->and($topUp->agent_id)->toBe('test-agent-001')
        ->and($topUp->external_reference)
        ->toBe('test-reference-001')
        ->and($wallet->available_balance_cents)->toBe(0)
        ->and(
            LedgerEntry::where(
                'wallet_id',
                $wallet->public_id
            )->count()
        )->toBe(0);
});
test('completes a top up and credits the wallet through the ledger', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'test-topup-user',
        WalletType::USUARIO
    );

    $service = app(TopUpService::class);

    $topUp = $service->create(
        $wallet,
        10050,
        TopUpMethod::EFECTIVO,
        'test-agent-001',
        'test-reference-001'
    );

    $completedTopUp = $service->complete(
        $topUp,
        'test-topup-complete'
    );

    $wallet->refresh();

    expect($completedTopUp->status)
        ->toBe(TopUpStatus::COMPLETADA)
        ->and($wallet->available_balance_cents)
        ->toBe(10050);

    $transaction = FinancialTransaction::where(
        'idempotency_key',
        'test-topup-complete'
    )->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->status)
        ->toBe(TransactionStatus::COMPLETADA)
        ->and($transaction->reference_type)
        ->toBe('TOPUP')
        ->and(strtolower($transaction->reference_id))
        ->toBe(strtolower($topUp->public_id));

    $entry = LedgerEntry::where(
        'transaction_id',
        $transaction->public_id
    )->first();

    expect($entry)->not->toBeNull()
        ->and($entry->movement_type)
        ->toBe(MovementType::RECARGA)
        ->and($entry->amount_cents)
        ->toBe(10050)
        ->and($entry->available_balance_after_cents)
        ->toBe(10050)
        ->and($entry->held_balance_after_cents)
        ->toBe(0);
});
test('does not allow the same top up to be completed twice', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'test-topup-user',
        WalletType::USUARIO
    );

    $service = app(TopUpService::class);

    $topUp = $service->create(
        $wallet,
        10050,
        TopUpMethod::EFECTIVO,
        'test-agent-001',
        'test-reference-001'
    );

    $service->complete(
        $topUp,
        'test-topup-first-completion'
    );

    expect(fn () => $service->complete(
        $topUp,
        'test-topup-second-completion'
    ))->toThrow(
        InvalidArgumentException::class,
        'Solo una recarga pendiente puede completarse.'
    );

    $wallet->refresh();

    expect($wallet->available_balance_cents)
        ->toBe(10050);
});
test('does not allow a top up with zero or negative amount', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'test-topup-user',
        WalletType::USUARIO
    );

    $service = app(TopUpService::class);

    expect(fn () => $service->create(
        $wallet,
        0,
        TopUpMethod::EFECTIVO
    ))->toThrow(
        InvalidArgumentException::class,
        'El monto de la recarga debe ser mayor que cero.'
    );

    expect(fn () => $service->create(
        $wallet,
        -100,
        TopUpMethod::EFECTIVO
    ))->toThrow(
        InvalidArgumentException::class,
        'El monto de la recarga debe ser mayor que cero.'
    );

    expect(
        TopUp::where(
            'wallet_id',
            $wallet->public_id
        )->count()
    )->toBe(0);

    $wallet->refresh();

    expect($wallet->available_balance_cents)->toBe(0);
});
test('does not allow creating a top up for an inactive wallet', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'test-topup-user',
        WalletType::USUARIO
    );

    $wallet->status = WalletStatus::BLOQUEADA;
    $wallet->save();

    $service = app(TopUpService::class);

    expect(fn () => $service->create(
        $wallet,
        10050,
        TopUpMethod::EFECTIVO
    ))->toThrow(
        InvalidArgumentException::class,
        'La wallet debe estar activa para recibir una recarga.'
    );

    expect(
        TopUp::where(
            'wallet_id',
            $wallet->public_id
        )->count()
    )->toBe(0);

    $wallet->refresh();

    expect($wallet->available_balance_cents)->toBe(0);
});
test('does not complete a top up if the wallet becomes inactive', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'test-topup-user',
        WalletType::USUARIO
    );

    $service = app(TopUpService::class);

    $topUp = $service->create(
        $wallet,
        10050,
        TopUpMethod::EFECTIVO,
        'test-agent-001'
    );

    $wallet->status = WalletStatus::BLOQUEADA;
    $wallet->save();

    expect(fn () => $service->complete(
        $topUp,
        'test-topup-inactive-wallet'
    ))->toThrow(
        InvalidArgumentException::class,
        'La wallet debe estar activa para recibir una recarga.'
    );

    $wallet->refresh();
    $topUp->refresh();

    expect($wallet->available_balance_cents)->toBe(0)
        ->and($topUp->status)->toBe(TopUpStatus::PENDIENTE)
        ->and(
            FinancialTransaction::where(
                'idempotency_key',
                'test-topup-inactive-wallet'
            )->count()
        )->toBe(0);
});
test('allows multiple pending top ups before folios are assigned', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'test-topup-user',
        WalletType::USUARIO
    );

    $service = app(TopUpService::class);

    $firstTopUp = $service->create(
        $wallet,
        10000,
        TopUpMethod::EFECTIVO,
        'test-agent-001'
    );

    $secondTopUp = $service->create(
        $wallet,
        20000,
        TopUpMethod::EFECTIVO,
        'test-agent-001'
    );

    expect($firstTopUp->folio)->toBeNull()
        ->and($secondTopUp->folio)->toBeNull()
        ->and($firstTopUp->status)
        ->toBe(TopUpStatus::PENDIENTE)
        ->and($secondTopUp->status)
        ->toBe(TopUpStatus::PENDIENTE)
        ->and(
            TopUp::where(
                'wallet_id',
                $wallet->public_id
            )->count()
        )->toBe(2);

    $wallet->refresh();

    expect($wallet->available_balance_cents)->toBe(0);
});
test('does not allow duplicate non null top up folios', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'test-topup-user',
        WalletType::USUARIO
    );

    $service = app(TopUpService::class);

    $firstTopUp = $service->create(
        $wallet,
        10000,
        TopUpMethod::EFECTIVO,
        'test-agent-001'
    );

    $secondTopUp = $service->create(
        $wallet,
        20000,
        TopUpMethod::EFECTIVO,
        'test-agent-001'
    );

    $firstTopUp->folio = 'TEST-FOLIO-001';
    $firstTopUp->save();

    $secondTopUp->folio = 'TEST-FOLIO-001';

    expect(fn () => $secondTopUp->save())
        ->toThrow(\Illuminate\Database\QueryException::class);
});