<?php

use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\WalletService;

afterEach(function () {
    Wallet::where('owner_id', 'test-wallet-user')->delete();
});

test('creates a user wallet with the correct initial values', function () {
    $service = app(WalletService::class);

    $wallet = $service->create(
        'USER',
        'test-wallet-user',
        WalletType::USUARIO
    );

    expect($wallet->public_id)->not->toBeEmpty()
        ->and($wallet->owner_type)->toBe('USER')
        ->and($wallet->owner_id)->toBe('test-wallet-user')
        ->and($wallet->type)->toBe(WalletType::USUARIO)
        ->and($wallet->currency)->toBe('MXN')
        ->and($wallet->status)->toBe(WalletStatus::ACTIVA)
        ->and($wallet->available_balance_cents)->toBe(0)
        ->and($wallet->held_balance_cents)->toBe(0);
});