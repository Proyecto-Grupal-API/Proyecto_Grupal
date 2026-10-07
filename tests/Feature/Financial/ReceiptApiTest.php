<?php

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\WalletService;
use App\Http\Middleware\ValidateServiceToken;

afterEach(function () {
    $transactions = FinancialTransaction::where(
        'idempotency_key',
        'test-receipt-api-credit'
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
        'test-receipt-api-user'
    )->delete();
});

test('returns a transaction receipt through the financial api', function () {
    $this->withoutMiddleware(
        ValidateServiceToken::class
    );

    $wallet = app(WalletService::class)->create(
        'USER',
        'test-receipt-api-user',
        WalletType::USUARIO
    );

    $transaction = app(LedgerService::class)->credit(
        $wallet,
        9500,
        MovementType::RECARGA,
        'test-receipt-api-credit',
        'TEST',
        'receipt-api-001'
    );

    $response = $this->getJson(
        '/api/v1/financial/transactions/'
            . $transaction->public_id
            . '/receipt'
    );

    $response
        ->assertOk()
        ->assertJsonPath(
            'data.transaction_id',
            $transaction->public_id
        )
        ->assertJsonPath(
            'data.status',
            'COMPLETADA'
        )
        ->assertJsonPath(
            'data.entries.0.movement_type',
            'RECARGA'
        )
        ->assertJsonPath(
            'data.entries.0.amount_cents',
            9500
        )
        ->assertJsonPath(
            'data.entries.0.currency',
            'MXN'
        )
        ->assertJsonPath(
            'meta.api_version',
            'v1'
        );
});
