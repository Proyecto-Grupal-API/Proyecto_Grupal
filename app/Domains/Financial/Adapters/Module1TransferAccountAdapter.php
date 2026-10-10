<?php
namespace App\Domains\Financial\Adapters;
use App\Domains\Financial\Contracts\TransferAccountProvider;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use App\Models\User;
use InvalidArgumentException;
use Throwable;
class Module1TransferAccountAdapter implements TransferAccountProvider
{
    public function assertActive(string $userId): void
    {
        if (!preg_match('/^[a-f0-9]{24}$/i', $userId)) { throw new InvalidArgumentException('Identificador de cuenta inválido.'); }
        try { $user = User::find(strtolower($userId)); }
        catch (Throwable $e) { throw new FinancialDependencyUnavailableException('La validación de cuentas no está disponible.', 0, $e); }
        if (!$user || $user->account_activation_pending || !$user->email_verified_at) {
            throw new InvalidArgumentException('El emisor y destinatario deben tener cuentas activas y verificadas.');
        }
    }
}
