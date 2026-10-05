<?php

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\FinancialAdjustmentService;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\WalletService;

$adjustmentOwners = [
    'submodule-2-7-hold-user',
    'submodule-2-7-release-user',
    'submodule-2-7-refund-user',
    'submodule-2-7-reverse-user',
];

afterEach(function () use ($adjustmentOwners) {
    $wallets = Wallet::whereIn('owner_id', $adjustmentOwners)->get();
    $walletIds = $wallets->pluck('public_id')->all();

    $transactions = FinancialTransaction::whereIn(
        'idempotency_key',
        [
            '2-7-hold',
            '2-7-release',
            '2-7-refund',
            '2-7-refund-second',
            '2-7-reverse',
        ]
    )->get();

    $transactionIds = $transactions->pluck('public_id')->all();

    if ($transactionIds) {
        LedgerEntry::whereIn('transaction_id', $transactionIds)->delete();
        FinancialTransaction::whereIn('public_id', $transactionIds)->delete();
    }

    if ($walletIds) {
        $otherTransactions = LedgerEntry::whereIn('wallet_id', $walletIds)
            ->pluck('transaction_id')
            ->all();

        LedgerEntry::whereIn('wallet_id', $walletIds)->delete();
        FinancialTransaction::whereIn('public_id', $otherTransactions)->delete();
    }

    Wallet::whereIn('owner_id', $adjustmentOwners)->delete();
});

test('creates a hold atomically and can release it once', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'submodule-2-7-hold-user',
        WalletType::USUARIO
    );
    $wallet->update(['available_balance_cents' => 20000]);

    $ledger = app(LedgerService::class);

    $hold = $ledger->hold(
        $wallet,
        7500,
        '2-7-hold',
        'PURCHASE',
        'purchase-2-7'
    );

    $wallet->refresh();

    expect($hold->status)->toBe(TransactionStatus::COMPLETADA)
        ->and($wallet->available_balance_cents)->toBe(12500)
        ->and($wallet->held_balance_cents)->toBe(7500);

    $entry = LedgerEntry::where('transaction_id', $hold->public_id)->first();

    expect($entry->movement_type)->toBe(MovementType::RETENCION)
        ->and($entry->amount_cents)->toBe(-7500)
        ->and($entry->available_balance_after_cents)->toBe(12500)
        ->and($entry->held_balance_after_cents)->toBe(7500);

    $released = $ledger->release($hold, '2-7-release');

    $wallet->refresh();

    expect($released->status)->toBe(TransactionStatus::COMPLETADA)
        ->and($released->original_transaction_id)->toBe($hold->public_id)
        ->and($wallet->available_balance_cents)->toBe(20000)
        ->and($wallet->held_balance_cents)->toBe(0);

    expect(
        LedgerEntry::where('transaction_id', $released->public_id)
            ->where('movement_type', MovementType::LIBERACION->value)
            ->count()
    )->toBe(1);

    expect(
        fn () => $ledger->release($hold, '2-7-release-second')
    )->toThrow(
        InvalidArgumentException::class,
        'La retención ya fue liberada.'
    );
});

test('rejects a hold when the available balance is insufficient', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'submodule-2-7-release-user',
        WalletType::USUARIO
    );

    expect(
        fn () => app(LedgerService::class)->hold(
            $wallet,
            1,
            '2-7-release',
            'PURCHASE',
            'insufficient-hold'
        )
    )->toThrow(
        InvalidArgumentException::class,
        'Saldo insuficiente para crear la retención.'
    );
});

test('creates a pending refund request without moving wallet balance', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'submodule-2-7-refund-user',
        WalletType::USUARIO
    );
    $wallet->update(['available_balance_cents' => 20000]);

    $original = app(LedgerService::class)->debit(
        $wallet,
        12000,
        MovementType::PAGO,
        '2-7-hold',
        'PURCHASE',
        'purchase-refund-2-7'
    );

    $refund = app(FinancialAdjustmentService::class)->refund(
        $original,
        5000,
        '2-7-refund',
        'Compra cancelada'
    );

    $wallet->refresh();

    expect($refund->status)->toBe(TransactionStatus::PENDIENTE)
        ->and($refund->reference_type)->toBe('REFUND_REQUEST')
        ->and($refund->original_transaction_id)->toBe($original->public_id)
        ->and($refund->metadata['amount_cents'])->toBe(5000)
        ->and($wallet->available_balance_cents)->toBe(8000)
        ->and(
            LedgerEntry::where('transaction_id', $refund->public_id)->count()
        )->toBe(0);
});

test('reverses a completed payment atomically', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'submodule-2-7-reverse-user',
        WalletType::USUARIO
    );
    $wallet->update(['available_balance_cents' => 15000]);

    $original = app(LedgerService::class)->debit(
        $wallet,
        6000,
        MovementType::PAGO,
        '2-7-hold',
        'PURCHASE',
        'purchase-reverse-2-7'
    );

    $reversal = app(FinancialAdjustmentService::class)->reverse(
        $original,
        '2-7-reverse',
        'Corrección administrativa'
    );

    $wallet->refresh();
    $original->refresh();

    expect($original->status)->toBe(TransactionStatus::REVERTIDA)
        ->and($reversal->status)->toBe(TransactionStatus::COMPLETADA)
        ->and($reversal->original_transaction_id)->toBe($original->public_id)
        ->and($wallet->available_balance_cents)->toBe(15000);

    $entry = LedgerEntry::where('transaction_id', $reversal->public_id)->first();

    expect($entry->movement_type)->toBe(MovementType::REVERSO)
        ->and($entry->amount_cents)->toBe(6000);

    expect(
        fn () => app(FinancialAdjustmentService::class)->reverse(
            $original,
            '2-7-reverse-again'
        )
    )->toThrow(
        InvalidArgumentException::class,
        'Solo se pueden reversar operaciones completadas.'
    );
});
