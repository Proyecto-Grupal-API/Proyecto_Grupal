<?php

namespace App\Domains\Financial\Adapters;

use App\Domains\Financial\Contracts\TransferAuthorizationProvider;
use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\Wallet;
use App\Services\IdentityService;

final class PersonalWalletTransferAuthorization implements TransferAuthorizationProvider
{
    public function __construct(private readonly IdentityService $identity) {}

    public function canSend(string $userId, Wallet $wallet): bool
    {
        return $this->isEligibleOwner($userId, $wallet);
    }

    public function canReceive(string $userId, Wallet $wallet): bool
    {
        return $this->isEligibleOwner($userId, $wallet);
    }

    public function canManagePolicy(string $userId): bool
    {
        return false;
    }

    private function isEligibleOwner(string $userId, Wallet $wallet): bool
    {
        return config('financial.personal_wallet_transfers_enabled') === true
            && $wallet->owner_type === 'USER' && strtolower((string) $wallet->owner_id) === $userId
            && $wallet->type === WalletType::USUARIO && $wallet->currency === 'MXN'
            && $wallet->status === WalletStatus::ACTIVA
            && $this->identity->isEligibleFinancialAccount($userId);
    }
}
