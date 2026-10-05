<?php

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\WalletService;
use App\Http\Middleware\ValidateServiceToken;

$apiAdjustmentOwners = [
    'api-2-7-hold-user',
    'api-2-7-refund-user',
];

afterEach(function () use ($apiAdjustmentOwners) {
    $wallets = Wallet::whereIn('owner_id', $apiAdjustmentOwners)->get();
    $walletIds = $wallets->pluck('public_id')->all();

    if ($walletIds) {
        $transactionIds = LedgerEntry::whereIn('wallet_id', $walletIds)
            ->pluck('transaction_id')
            ->all();

        LedgerEntry::whereIn('wallet_id', $walletIds)->delete();
        FinancialTransaction::whereIn('public_id', $transactionIds)->delete();
    }

    Wallet::whereIn('owner_id', $apiAdjustmentOwners)->delete();
});

test('creates and releases a hold through the financial api', function () {
    $this->withoutMiddleware(ValidateServiceToken::class);

    $wallet = app(WalletService::class)->create(
        'USER',
        'api-2-7-hold-user',
        WalletType::USUARIO
    );
    $wallet->update(['available_balance_cents' => 30000]);

    $create = $this->postJson(
        '/api/v1/financial/wallets/' . $wallet->public_id . '/holds',
        [
            'amount_cents' => 10000,
            'reference_type' => 'PURCHASE',
            'reference_id' => 'api-purchase-2-7',
        ],
        ['Idempotency-Key' => 'api-2-7-hold-key']
    );

    $create
        ->assertCreated()
        ->assertJsonPath('data.status', 'COMPLETADA')
        ->assertJsonPath('data.entries.0.movement_type', 'RETENCION')
        ->assertJsonPath('meta.api_version', 'v1');

    $holdId = $create->json('data.id');

    $release = $this->postJson(
        '/api/v1/financial/holds/' . $holdId . '/release',
        [],
        ['Idempotency-Key' => 'api-2-7-release-key']
    );

    $release
        ->assertOk()
        ->assertJsonPath('data.original_transaction_id', $holdId)
        ->assertJsonPath('data.entries.0.movement_type', 'LIBERACION');

    $wallet->refresh();

    expect($wallet->available_balance_cents)->toBe(30000)
        ->and($wallet->held_balance_cents)->toBe(0);
});

test('creates a pending refund request through the financial api', function () {
    $this->withoutMiddleware(ValidateServiceToken::class);

    $wallet = app(WalletService::class)->create(
        'USER',
        'api-2-7-refund-user',
        WalletType::USUARIO
    );
    $wallet->update(['available_balance_cents' => 20000]);

    $original = app(LedgerService::class)->debit(
        $wallet,
        9000,
        MovementType::PAGO,
        'api-2-7-original-payment',
        'PURCHASE',
        'api-purchase-refund-2-7'
    );

    $response = $this->postJson(
        '/api/v1/financial/transactions/' . $original->public_id . '/refund',
        [
            'amount_cents' => 4000,
            'reason' => 'Compra cancelada',
        ],
        ['Idempotency-Key' => 'api-2-7-refund-key']
    );

    $response
        ->assertCreated()
        ->assertJsonPath('data.reference_type', 'REFUND_REQUEST')
        ->assertJsonPath('data.status', 'PENDIENTE')
        ->assertJsonPath('data.original_transaction_id', $original->public_id)
        ->assertJsonPath('data.metadata.amount_cents', 4000)
        ->assertJsonPath('meta.api_version', 'v1');

    $wallet->refresh();

    expect($wallet->available_balance_cents)->toBe(11000)
        ->and($wallet->held_balance_cents)->toBe(0);
});
