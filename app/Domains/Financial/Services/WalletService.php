<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\Wallet;
use Illuminate\Support\Str;

class WalletService
{
    public function create(
        string $ownerType,
        string $ownerId,
        WalletType $walletType
    ): Wallet {
        return Wallet::create([
            'public_id' => (string) Str::uuid(),
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'type' => $walletType,
            'currency' => 'MXN',
            'status' => WalletStatus::ACTIVA,
            'available_balance_cents' => 0,
            'held_balance_cents' => 0,
        ]);
    }
}