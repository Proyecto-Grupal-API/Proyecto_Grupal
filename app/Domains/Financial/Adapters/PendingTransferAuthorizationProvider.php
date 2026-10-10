<?php
namespace App\Domains\Financial\Adapters;
use App\Domains\Financial\Contracts\TransferAuthorizationProvider;
use App\Domains\Financial\Models\Wallet;
class PendingTransferAuthorizationProvider implements TransferAuthorizationProvider
{
    public function canSend(string $userId, Wallet $wallet): bool { return false; }
    public function canReceive(string $userId, Wallet $wallet): bool { return false; }
    public function canManagePolicy(string $userId): bool { return false; }
}
