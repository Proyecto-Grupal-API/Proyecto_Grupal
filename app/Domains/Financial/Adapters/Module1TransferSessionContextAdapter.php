<?php
namespace App\Domains\Financial\Adapters;
use App\Domains\Financial\Contracts\TransferSessionContextProvider;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use App\Models\UserSession;
use Throwable;
class Module1TransferSessionContextAdapter implements TransferSessionContextProvider
{
    public function resolve(string $userId, ?string $registeredSessionId): array
    {
        if ($registeredSessionId === null || $registeredSessionId === '') {
            return ['session_id' => null, 'device_id' => null, 'status' => 'NOT_PROVIDED'];
        }
        try { $session = UserSession::where('_id', $registeredSessionId)->where('user_id', strtolower($userId))->first(); }
        catch (Throwable $e) { throw new FinancialDependencyUnavailableException('El contexto de sesión no está disponible.', 0, $e); }
        if (!$session || $session->isRevoked()) { return ['session_id' => null, 'device_id' => null, 'status' => 'NOT_VERIFIED']; }
        return ['session_id' => (string) $session->getKey(), 'device_id' => $session->device_id ? (string) $session->device_id : null, 'status' => 'VERIFIED'];
    }
}
