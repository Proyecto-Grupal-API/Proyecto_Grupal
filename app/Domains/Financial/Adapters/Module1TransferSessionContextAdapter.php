<?php

namespace App\Domains\Financial\Adapters;

use App\Domains\Financial\Contracts\TransferSessionContextProvider;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use App\Services\IdentityService;
use Throwable;

class Module1TransferSessionContextAdapter implements TransferSessionContextProvider
{
    public function __construct(private readonly IdentityService $identity) {}

    public function resolve(string $userId, ?string $registeredSessionId): array
    {
        if ($registeredSessionId === null || $registeredSessionId === '') {
            return ['session_id' => null, 'device_id' => null, 'status' => 'NOT_PROVIDED'];
        }
        try {
            $session = $this->identity->activeFinancialSession($userId, $registeredSessionId);
        } catch (Throwable $e) {
            throw new FinancialDependencyUnavailableException('El contexto de sesión no está disponible.', 0, $e);
        }
        if ($session === null) {
            return ['session_id' => null, 'device_id' => null, 'status' => 'NOT_VERIFIED'];
        }

        return $session + ['status' => 'VERIFIED'];
    }
}
