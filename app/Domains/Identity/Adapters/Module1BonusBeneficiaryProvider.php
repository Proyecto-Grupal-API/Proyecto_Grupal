<?php
namespace App\Domains\Identity\Adapters;
use App\Domains\Financial\Contracts\BonusBeneficiaryProvider;
use App\Domains\Identity\Exceptions\AuthorizationDependencyUnavailable;
use App\Models\User;
final class Module1BonusBeneficiaryProvider implements BonusBeneficiaryProvider
{
    public function findActive(string $userId): ?array
    {
        if (!preg_match('/\A[a-f0-9]{24}\z/i', $userId)) { return null; }
        try {
            $u = User::whereKey(strtolower($userId))->whereNull('deleted_at')->first();
            if (!$u || $u->account_activation_pending || $u->must_change_password || !$u->hasVerifiedEmail()) { return null; }
            return ['id' => (string)$u->getKey(), 'name' => $u->name];
        } catch (\Throwable $e) { throw new AuthorizationDependencyUnavailable('La identidad del beneficiario no está disponible.', 0, $e); }
    }
}
