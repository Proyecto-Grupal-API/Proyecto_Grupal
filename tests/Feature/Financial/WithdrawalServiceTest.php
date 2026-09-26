<?php

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Enums\WithdrawalMethod;
use App\Domains\Financial\Enums\WithdrawalStatus;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\Withdrawal;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\WalletService;
use App\Domains\Financial\Services\WithdrawalService;

afterEach(function () {
    $wallet = Wallet::where(
        'owner_id',
        'test-withdrawal-user'
    )->first();

    if ($wallet) {
        $withdrawalIds = Withdrawal::where(
            'wallet_id',
            $wallet->public_id
        )->pluck('public_id');

        $transactionIds = FinancialTransaction::where(function ($query) use (
            $withdrawalIds
        ) {
            $query->where('reference_type', 'WITHDRAWAL')
                ->whereIn('reference_id', $withdrawalIds);
        })
            ->orWhere(
                'idempotency_key',
                'test-withdrawal-initial-credit'
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

        Withdrawal::where(
            'wallet_id',
            $wallet->public_id
        )->delete();

        $wallet->delete();
    }
});

function createWithdrawalTestWallet(
    int $initialBalance = 0
): Wallet {
    $wallet = app(WalletService::class)->create(
        'USER',
        'test-withdrawal-user',
        WalletType::USUARIO
    );

    if ($initialBalance > 0) {
        app(LedgerService::class)->credit(
            $wallet,
            $initialBalance,
            MovementType::AJUSTE_CREDITO,
            'test-withdrawal-initial-credit',
            'TEST',
            $wallet->public_id
        );

        $wallet->refresh();
    }

    return $wallet;
}

test('creates a pending withdrawal without changing the wallet balance', function () {
    $wallet = createWithdrawalTestWallet(50000);

    $service = app(WithdrawalService::class);

    $withdrawal = $service->create(
        $wallet,
        10000,
        WithdrawalMethod::EFECTIVO,
        'test-agent-001',
        'test-reference-001'
    );

    $wallet->refresh();

    expect($withdrawal->public_id)->not->toBeEmpty()
        ->and(strtolower($withdrawal->wallet_id))
        ->toBe(strtolower($wallet->public_id))
        ->and($withdrawal->amount_cents)->toBe(10000)
        ->and($withdrawal->currency)->toBe('MXN')
        ->and($withdrawal->method)
        ->toBe(WithdrawalMethod::EFECTIVO)
        ->and($withdrawal->status)
        ->toBe(WithdrawalStatus::PENDIENTE)
        ->and($withdrawal->folio)->toBeNull()
        ->and($withdrawal->agent_id)
        ->toBe('test-agent-001')
        ->and($withdrawal->external_reference)
        ->toBe('test-reference-001')
        ->and($wallet->available_balance_cents)
        ->toBe(50000);
});

test('completes a withdrawal and debits the wallet through the ledger', function () {
    $wallet = createWithdrawalTestWallet(50000);

    $service = app(WithdrawalService::class);

    $withdrawal = $service->create(
        $wallet,
        10000,
        WithdrawalMethod::EFECTIVO,
        'test-agent-001'
    );

    $completedWithdrawal = $service->complete(
        $withdrawal,
        'test-withdrawal-complete'
    );

    $wallet->refresh();

    expect($completedWithdrawal->status)
        ->toBe(WithdrawalStatus::COMPLETADA)
        ->and($wallet->available_balance_cents)
        ->toBe(40000);

    $transaction = FinancialTransaction::where(
        'idempotency_key',
        'test-withdrawal-complete'
    )->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->status)
        ->toBe(TransactionStatus::COMPLETADA)
        ->and($transaction->reference_type)
        ->toBe('WITHDRAWAL')
        ->and(strtolower($transaction->reference_id))
        ->toBe(strtolower($withdrawal->public_id));

    $entry = LedgerEntry::where(
        'transaction_id',
        $transaction->public_id
    )->first();

    expect($entry)->not->toBeNull()
        ->and($entry->movement_type)
        ->toBe(MovementType::RETIRO)
        ->and($entry->amount_cents)
        ->toBe(-10000)
        ->and($entry->available_balance_after_cents)
        ->toBe(40000)
        ->and($entry->held_balance_after_cents)
        ->toBe(0);
});

test('does not allow the same withdrawal to be completed twice', function () {
    $wallet = createWithdrawalTestWallet(50000);

    $service = app(WithdrawalService::class);

    $withdrawal = $service->create(
        $wallet,
        10000,
        WithdrawalMethod::EFECTIVO
    );

    $service->complete(
        $withdrawal,
        'test-withdrawal-first-completion'
    );

    expect(fn () => $service->complete(
        $withdrawal,
        'test-withdrawal-second-completion'
    ))->toThrow(
        InvalidArgumentException::class,
        'El retiro debe estar pendiente para completarse.'
    );

    $wallet->refresh();

    expect($wallet->available_balance_cents)
        ->toBe(40000);
});

test('does not allow a withdrawal with zero or negative amount', function () {
    $wallet = createWithdrawalTestWallet(50000);

    $service = app(WithdrawalService::class);

    expect(fn () => $service->create(
        $wallet,
        0,
        WithdrawalMethod::EFECTIVO
    ))->toThrow(
        InvalidArgumentException::class,
        'El monto del retiro debe ser mayor que cero.'
    );

    expect(fn () => $service->create(
        $wallet,
        -100,
        WithdrawalMethod::EFECTIVO
    ))->toThrow(
        InvalidArgumentException::class,
        'El monto del retiro debe ser mayor que cero.'
    );

    expect(
        Withdrawal::where(
            'wallet_id',
            $wallet->public_id
        )->count()
    )->toBe(0);

    $wallet->refresh();

    expect($wallet->available_balance_cents)
        ->toBe(50000);
});

test('does not allow creating a withdrawal for an inactive wallet', function () {
    $wallet = createWithdrawalTestWallet(50000);

    $wallet->status = WalletStatus::BLOQUEADA;
    $wallet->save();

    $service = app(WithdrawalService::class);

    expect(fn () => $service->create(
        $wallet,
        10000,
        WithdrawalMethod::EFECTIVO
    ))->toThrow(
        InvalidArgumentException::class,
        'La wallet debe estar activa para solicitar un retiro.'
    );

    expect(
        Withdrawal::where(
            'wallet_id',
            $wallet->public_id
        )->count()
    )->toBe(0);
});

test('does not complete a withdrawal if the wallet becomes inactive', function () {
    $wallet = createWithdrawalTestWallet(50000);

    $service = app(WithdrawalService::class);

    $withdrawal = $service->create(
        $wallet,
        10000,
        WithdrawalMethod::EFECTIVO
    );

    $wallet->status = WalletStatus::BLOQUEADA;
    $wallet->save();

    expect(fn () => $service->complete(
        $withdrawal,
        'test-withdrawal-inactive-wallet'
    ))->toThrow(
        InvalidArgumentException::class,
        'La wallet debe estar activa para completar el retiro.'
    );

    $wallet->refresh();
    $withdrawal->refresh();

    expect($wallet->available_balance_cents)
        ->toBe(50000)
        ->and($withdrawal->status)
        ->toBe(WithdrawalStatus::PENDIENTE)
        ->and(
            FinancialTransaction::where(
                'idempotency_key',
                'test-withdrawal-inactive-wallet'
            )->count()
        )->toBe(0);
});

test('does not complete a withdrawal with insufficient balance', function () {
    $wallet = createWithdrawalTestWallet(5000);

    $service = app(WithdrawalService::class);

    $withdrawal = $service->create(
        $wallet,
        10000,
        WithdrawalMethod::TRANSFERENCIA,
        null,
        'external-account-test'
    );

    expect(fn () => $service->complete(
        $withdrawal,
        'test-withdrawal-insufficient-balance'
    ))->toThrow(
        InvalidArgumentException::class,
        'Saldo insuficiente.'
    );

    $wallet->refresh();
    $withdrawal->refresh();

    expect($wallet->available_balance_cents)
        ->toBe(5000)
        ->and($withdrawal->status)
        ->toBe(WithdrawalStatus::PENDIENTE)
        ->and(
            FinancialTransaction::where(
                'idempotency_key',
                'test-withdrawal-insufficient-balance'
            )->count()
        )->toBe(0);
});