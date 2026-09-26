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
    $transactions = FinancialTransaction::whereIn(
        'idempotency_key',
        [
            'test-ledger-credit',
            'test-ledger-debit',
            'test-ledger-insufficient',
            'test-ledger-invalid-debit',
            'test-transfer-success',
            'test-transfer-insufficient',
            'test-transfer-same-wallet',
            'test-transfer-invalid-amount',
            'test-transfer-currency',
            'test-transfer-idempotency',
        ]
    )->get();

    foreach ($transactions as $transaction) {
        LedgerEntry::where(
            'transaction_id',
            $transaction->public_id
        )->delete();

        $transaction->delete();
    }

    Wallet::whereIn(
        'owner_id',
        [
            'test-ledger-user',
            'transfer-source-001',
            'transfer-destination-001',
            'transfer-insufficient-source',
            'transfer-insufficient-destination',
            'transfer-same-wallet',
            'transfer-invalid-source',
            'transfer-invalid-destination',
            'transfer-currency-source',
            'transfer-currency-destination',
            'transfer-idempotency-source',
            'transfer-idempotency-destination',
        ]
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
    ->and($entry->balance_after_cents)->toBe(10050)
    ->and($entry->available_balance_after_cents)->toBe(10050)
    ->and($entry->held_balance_after_cents)->toBe(0);

    $sameTransaction = $ledger->credit(
        $wallet,
        10050,
        MovementType::RECARGA,
        'test-ledger-credit',
        'TEST',
        'credit-001'
    );

    $wallet->refresh();

    expect(strtolower($sameTransaction->public_id))
    ->toBe(strtolower($transaction->public_id))
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

test('debits a wallet and records a negative ledger entry', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'test-ledger-user',
        WalletType::USUARIO
    );

    $wallet->update([
        'available_balance_cents' => 10000,
    ]);

    $ledger = app(LedgerService::class);

    $transaction = $ledger->debit(
        $wallet,
        3000,
        MovementType::RETIRO,
        'test-ledger-debit',
        'TEST',
        'debit-001'
    );

    $wallet->refresh();

    expect($wallet->available_balance_cents)->toBe(7000)
        ->and($transaction->status)->toBe(TransactionStatus::COMPLETADA);

    $entry = LedgerEntry::where(
        'transaction_id',
        $transaction->public_id
    )->first();

    expect($entry)->not->toBeNull()
        ->and($entry->wallet_id)->toBe($wallet->public_id)
        ->and($entry->movement_type)->toBe(MovementType::RETIRO)
        ->and($entry->amount_cents)->toBe(-3000)
        ->and($entry->balance_after_cents)->toBe(7000)
        ->and($entry->available_balance_after_cents)->toBe(7000)
        ->and($entry->held_balance_after_cents)->toBe(0);
});

test('rejects a debit when the wallet has insufficient balance', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'test-ledger-user',
        WalletType::USUARIO
    );

    $wallet->update([
        'available_balance_cents' => 10000,
    ]);

    $ledger = app(LedgerService::class);

    expect(
        fn () => $ledger->debit(
            $wallet,
            15000,
            MovementType::RETIRO,
            'test-ledger-insufficient'
        )
    )->toThrow(
        InvalidArgumentException::class,
        'Saldo insuficiente.'
    );

    $wallet->refresh();

    expect($wallet->available_balance_cents)->toBe(10000);
});

test('rejects zero or negative debit amounts', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'test-ledger-user',
        WalletType::USUARIO
    );

    $ledger = app(LedgerService::class);

    expect(
        fn () => $ledger->debit(
            $wallet,
            0,
            MovementType::RETIRO,
            'test-ledger-invalid-debit'
        )
    )->toThrow(
        InvalidArgumentException::class,
        'El monto debe ser mayor que cero.'
    );
});
test('transfers money between wallets and records both ledger entries', function () {
    $walletService = app(WalletService::class);
    $ledger = app(LedgerService::class);

    $source = $walletService->create(
        'USER',
        'transfer-source-001',
        WalletType::USUARIO
    );

    $destination = $walletService->create(
        'USER',
        'transfer-destination-001',
        WalletType::USUARIO
    );

    $source->available_balance_cents = 100000;
    $source->save();

    $transaction = $ledger->transfer(
        $source,
        $destination,
        30000,
        'test-transfer-success',
        'TEST',
        'transfer-001'
    );

    $source->refresh();
    $destination->refresh();

    expect($transaction->status)
        ->toBe(TransactionStatus::COMPLETADA)
        ->and($source->available_balance_cents)
        ->toBe(70000)
        ->and($destination->available_balance_cents)
        ->toBe(30000);

    $entries = LedgerEntry::where(
        'transaction_id',
        $transaction->public_id
    )->get();

    expect($entries)->toHaveCount(2);

    $outgoing = $entries->firstWhere(
        'movement_type',
        MovementType::TRANSFERENCIA_SALIDA
    );

    $incoming = $entries->firstWhere(
        'movement_type',
        MovementType::TRANSFERENCIA_ENTRADA
    );

    expect($outgoing)->not->toBeNull()
    ->and($outgoing->amount_cents)
        ->toBe(-30000)
    ->and($outgoing->balance_after_cents)
        ->toBe(70000)
    ->and($outgoing->available_balance_after_cents)
        ->toBe(70000)
    ->and($outgoing->held_balance_after_cents)
        ->toBe(0)
    ->and($incoming)->not->toBeNull()
    ->and($incoming->amount_cents)
        ->toBe(30000)
    ->and($incoming->balance_after_cents)
        ->toBe(30000)
    ->and($incoming->available_balance_after_cents)
        ->toBe(30000)
    ->and($incoming->held_balance_after_cents)
        ->toBe(0);

});

 test('rejects a transfer when the source wallet has insufficient balance', function () {
    $walletService = app(WalletService::class);
    $ledger = app(LedgerService::class);

    $source = $walletService->create(
        'USER',
        'transfer-insufficient-source',
        WalletType::USUARIO
    );

    $destination = $walletService->create(
        'USER',
        'transfer-insufficient-destination',
        WalletType::USUARIO
    );

    $source->update([
        'available_balance_cents' => 10000,
    ]);

    expect(
        fn () => $ledger->transfer(
            $source,
            $destination,
            15000,
            'test-transfer-insufficient'
        )
    )->toThrow(
        InvalidArgumentException::class,
        'Saldo insuficiente.'
    );

    $source->refresh();
    $destination->refresh();

    expect($source->available_balance_cents)
        ->toBe(10000)
        ->and($destination->available_balance_cents)
        ->toBe(0)
        ->and(
            FinancialTransaction::where(
                'idempotency_key',
                'test-transfer-insufficient'
            )->count()
        )->toBe(0);
});

test('rejects a transfer to the same wallet', function () {
    $walletService = app(WalletService::class);
    $ledger = app(LedgerService::class);

    $wallet = $walletService->create(
        'USER',
        'transfer-same-wallet',
        WalletType::USUARIO
    );

    $wallet->update([
        'available_balance_cents' => 50000,
    ]);

    expect(
        fn () => $ledger->transfer(
            $wallet,
            $wallet,
            10000,
            'test-transfer-same-wallet'
        )
    )->toThrow(
        InvalidArgumentException::class,
        'La wallet de origen y destino deben ser diferentes.'
    );

    $wallet->refresh();

    expect($wallet->available_balance_cents)
        ->toBe(50000)
        ->and(
            FinancialTransaction::where(
                'idempotency_key',
                'test-transfer-same-wallet'
            )->count()
        )->toBe(0);
});

test('rejects zero or negative transfer amounts', function () {
    $walletService = app(WalletService::class);
    $ledger = app(LedgerService::class);

    $source = $walletService->create(
        'USER',
        'transfer-invalid-source',
        WalletType::USUARIO
    );

    $destination = $walletService->create(
        'USER',
        'transfer-invalid-destination',
        WalletType::USUARIO
    );

    $source->update([
        'available_balance_cents' => 50000,
    ]);

    expect(
        fn () => $ledger->transfer(
            $source,
            $destination,
            0,
            'test-transfer-invalid-amount'
        )
    )->toThrow(
        InvalidArgumentException::class,
        'El monto debe ser mayor que cero.'
    );

    expect(
        fn () => $ledger->transfer(
            $source,
            $destination,
            -10000,
            'test-transfer-invalid-amount'
        )
    )->toThrow(
        InvalidArgumentException::class,
        'El monto debe ser mayor que cero.'
    );

    $source->refresh();
    $destination->refresh();

    expect($source->available_balance_cents)
        ->toBe(50000)
        ->and($destination->available_balance_cents)
        ->toBe(0)
        ->and(
            FinancialTransaction::where(
                'idempotency_key',
                'test-transfer-invalid-amount'
            )->count()
        )->toBe(0);
});

test('rejects a transfer between wallets with different currencies', function () {
    $walletService = app(WalletService::class);
    $ledger = app(LedgerService::class);

    $source = $walletService->create(
        'USER',
        'transfer-currency-source',
        WalletType::USUARIO
    );

    $destination = $walletService->create(
        'USER',
        'transfer-currency-destination',
        WalletType::USUARIO
    );

    $source->update([
        'available_balance_cents' => 50000,
    ]);

    $destination->update([
        'currency' => 'USD',
    ]);

    expect(
        fn () => $ledger->transfer(
            $source,
            $destination,
            10000,
            'test-transfer-currency'
        )
    )->toThrow(
        InvalidArgumentException::class,
        'Las wallets deben utilizar la misma moneda.'
    );

    $source->refresh();
    $destination->refresh();

    expect($source->available_balance_cents)
        ->toBe(50000)
        ->and($destination->available_balance_cents)
        ->toBe(0)
        ->and(
            FinancialTransaction::where(
                'idempotency_key',
                'test-transfer-currency'
            )->count()
        )->toBe(0);
});

test('does not execute the same transfer twice', function () {
    $walletService = app(WalletService::class);
    $ledger = app(LedgerService::class);

    $source = $walletService->create(
        'USER',
        'transfer-idempotency-source',
        WalletType::USUARIO
    );

    $destination = $walletService->create(
        'USER',
        'transfer-idempotency-destination',
        WalletType::USUARIO
    );

    $source->update([
        'available_balance_cents' => 100000,
    ]);

    $firstTransaction = $ledger->transfer(
        $source,
        $destination,
        30000,
        'test-transfer-idempotency'
    );

    $secondTransaction = $ledger->transfer(
        $source,
        $destination,
        30000,
        'test-transfer-idempotency'
    );

    $source->refresh();
    $destination->refresh();

    expect(strtolower($secondTransaction->public_id))
        ->toBe(strtolower($firstTransaction->public_id))
        ->and($source->available_balance_cents)
        ->toBe(70000)
        ->and($destination->available_balance_cents)
        ->toBe(30000)
        ->and(
            FinancialTransaction::where(
                'idempotency_key',
                'test-transfer-idempotency'
            )->count()
        )->toBe(1)
        ->and(
            LedgerEntry::where(
                'transaction_id',
                $firstTransaction->public_id
            )->count()
        )->toBe(2);
});