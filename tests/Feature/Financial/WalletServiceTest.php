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
test('finds a user wallet by owner', function () {
    $service = app(WalletService::class);

    $createdWallet = $service->create(
        'USER',
        'test-wallet-user',
        WalletType::USUARIO
    );

    $foundWallet = $service->findByOwner(
        'USER',
        'test-wallet-user',
        WalletType::USUARIO
    );

    expect($foundWallet)->not->toBeNull()
        ->and(strtolower($foundWallet->public_id))
        ->toBe(strtolower($createdWallet->public_id))
        ->and($foundWallet->owner_type)->toBe('USER')
        ->and($foundWallet->owner_id)->toBe('test-wallet-user')
        ->and($foundWallet->type)->toBe(WalletType::USUARIO);
});

test('returns null when the owner wallet does not exist', function () {
    $service = app(WalletService::class);

    $wallet = $service->findByOwner(
        'USER',
        'test-wallet-user',
        WalletType::USUARIO
    );

    expect($wallet)->toBeNull();
});