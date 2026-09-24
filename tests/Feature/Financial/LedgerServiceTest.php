<?php

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\WalletService;

afterEach(function () {
    $transaction = FinancialTransaction::where(
        'idempotency_key',
        'test-ledger-credit'
    )->first();

    if ($transaction) {
        LedgerEntry::where(
            'transaction_id',
            $transaction->public_id
        )->delete();

        $transaction->delete();
    }

    Wallet::where(
        'owner_id',
        'test-ledger-user'
    )->delete();
});

test('credits a wallet and records the operation in the ledger', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'test-ledger-user',
        WalletType::USUARIO
    );

    $ledger = app(LedgerService::class);

    $transaction = $ledger->credit(
        $wallet,
        10050,
        MovementType::RECARGA,
        'test-ledger-credit',
        'TEST',
        'credit-001'
    );

    $wallet->refresh();

    expect($wallet->available_balance_cents)->toBe(10050)
        ->and($transaction->status)->toBe(TransactionStatus::COMPLETADA);

    $entry = LedgerEntry::where(
        'transaction_id',
        $transaction->public_id
    )->first();

    expect($entry)->not->toBeNull()
        ->and($entry->wallet_id)->toBe($wallet->public_id)
        ->and($entry->movement_type)->toBe(MovementType::RECARGA)
        ->and($entry->amount_cents)->toBe(10050)
        ->and($entry->balance_after_cents)->toBe(10050);

    $sameTransaction = $ledger->credit(
        $wallet,
        10050,
        MovementType::RECARGA,
        'test-ledger-credit',
        'TEST',
        'credit-001'
    );

    $wallet->refresh();

    expect($sameTransaction->public_id)->toBe($transaction->public_id)
        ->and($wallet->available_balance_cents)->toBe(10050)
        ->and(
            LedgerEntry::where(
                'transaction_id',
                $transaction->public_id
            )->count()
        )->toBe(1);
});

test('rejects zero or negative credit amounts', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'test-ledger-user',
        WalletType::USUARIO
    );

    $ledger = app(LedgerService::class);

    expect(
        fn () => $ledger->credit(
            $wallet,
            0,
            MovementType::RECARGA,
            'test-ledger-credit'
        )
    )->toThrow(
        InvalidArgumentException::class,
        'El monto debe ser mayor que cero.'
    );
});