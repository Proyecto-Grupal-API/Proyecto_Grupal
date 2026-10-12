<?php
namespace App\Domains\Financial\Adapters;
use App\Domains\Financial\Contracts\ReceiptAdministrativeAuthorization;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use CampusDigital\Authorization\Kernel;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
class CampusReceiptAdministrativeAuthorization implements ReceiptAdministrativeAuthorization
{
    public function __construct(private Kernel $kernel) {}
    public function assertRead(string $userId, ?array $scope): void {
        if (!config('campus_authorization.enabled')) throw new FinancialDependencyUnavailableException('La autorización del campus está desactivada.');
        try {
            $principal = $this->kernel->principalFor($userId);
            $allowed = $principal && $this->kernel->check($principal, 'module.2.9.read', $scope)->allowed();
        } catch (\Throwable $e) {
            report($e);
            throw new FinancialDependencyUnavailableException('La autorización del campus no está disponible.');
        }
        if (!$allowed) throw new AccessDeniedHttpException('No tienes permiso para consultar comprobantes en este contexto.');
    }
}
