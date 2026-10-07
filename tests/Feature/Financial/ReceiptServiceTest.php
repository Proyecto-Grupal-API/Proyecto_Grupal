<?php

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\ReceiptService;
use App\Domains\Financial\Services\WalletService;
use Illuminate\Database\Eloquent\ModelNotFoundException;

afterEach(function () {
    $transactions = FinancialTransaction::whereIn(
        'idempotency_key',
        [
            'test-receipt-credit',
            'test-receipt-other-credit',
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
            'test-receipt-user',
            'test-receipt-other-user',
        ]
    )->delete();
});

test('builds a receipt from a ledger transaction', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'test-receipt-user',
        WalletType::USUARIO
    );

    $transaction = app(LedgerService::class)->credit(
        $wallet,
        12500,
        MovementType::RECARGA,
        'test-receipt-credit',
        'TEST',
        'receipt-001'
    );

    $receipt = app(ReceiptService::class)->forTransaction(
        $transaction->public_id
    );

    expect(strtolower($receipt['transaction_id']))
        ->toBe(strtolower($transaction->public_id))
        ->and($receipt['status'])
        ->toBe('COMPLETADA')
        ->and($receipt['reference_type'])
        ->toBe('TEST')
        ->and($receipt['reference_id'])
        ->toBe('receipt-001')
        ->and($receipt['entries'])
        ->toHaveCount(1)
        ->and($receipt['entries'][0]['movement_type'])
        ->toBe('RECARGA')
        ->and($receipt['entries'][0]['amount_cents'])
        ->toBe(12500)
        ->and($receipt['entries'][0]['currency'])
        ->toBe('MXN');
});

test('wallet receipt only exposes transactions belonging to that wallet', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'test-receipt-user',
        WalletType::USUARIO
    );

    $otherWallet = app(WalletService::class)->create(
        'USER',
        'test-receipt-other-user',
        WalletType::USUARIO
    );

    $transaction = app(LedgerService::class)->credit(
        $otherWallet,
        8000,
        MovementType::RECARGA,
        'test-receipt-other-credit',
        'TEST',
        'receipt-other'
    );

    expect(
        fn () => app(ReceiptService::class)->forWallet(
            $wallet,
            $transaction->public_id
        )
    )->toThrow(ModelNotFoundException::class);
});
