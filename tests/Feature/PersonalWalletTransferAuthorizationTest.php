<?php

use App\Domains\Financial\Contracts\TransferAuthorizationProvider;
use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\Wallet;
use App\Models\User;

test('personal transfers require an enabled environment, an active identity and its own MXN wallet', function () {
    $owner = User::factory()->create(['email_verified_at' => now(), 'must_change_password' => false,
        'account_activation_pending' => false]);
    $id = (string) $owner->id;
    $wallet = new Wallet(['owner_type' => 'USER', 'owner_id' => $id, 'type' => WalletType::USUARIO,
        'status' => WalletStatus::ACTIVA, 'currency' => 'MXN']);
    $policy = app(TransferAuthorizationProvider::class);
    expect($policy->canSend($id, $wallet))->toBeFalse();
    config(['financial.personal_wallet_transfers_enabled' => true]);

    expect($policy->canSend($id, $wallet))->toBeTrue()
        ->and($policy->canReceive($id, $wallet))->toBeTrue()
        ->and($policy->canManagePolicy($id))->toBeFalse();
    $wrong = clone $wallet;
    $wrong->owner_id = bin2hex(random_bytes(12));
    expect($policy->canSend($id, $wrong))->toBeFalse();
    $wrong = clone $wallet;
    $wrong->currency = 'USD';
    expect($policy->canReceive($id, $wrong))->toBeFalse();
    $owner->forceFill(['account_activation_pending' => true])->save();
    expect($policy->canSend($id, $wallet))->toBeFalse()
        ->and($policy->canReceive($id, $wallet))->toBeFalse();
});
