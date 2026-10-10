<?php
namespace App\Domains\Financial\Contracts;
use App\Domains\Financial\Models\Wallet;
/** Trusted identity/role integration. Never derive grants from self-assigned roles. */
interface TransferAuthorizationProvider
{
    public function canSend(string $userId, Wallet $wallet): bool;
    public function canReceive(string $userId, Wallet $wallet): bool;
    public function canManagePolicy(string $userId): bool;
}
