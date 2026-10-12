<?php
namespace App\Domains\Financial\Adapters;
use App\Domains\Financial\Contracts\LegacyCashReceiptAuthorization;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use CampusDigital\Authorization\Kernel;
class CampusLegacyCashReceiptAuthorization implements LegacyCashReceiptAuthorization
{
    public function __construct(private Kernel $kernel) {}
    public function assertGlobal(string $userId): void {
        if (config('financial_receipts.administration_provider')!=='campus'||!config('campus_authorization.enabled')) {
            throw new FinancialDependencyUnavailableException('La autorización histórica no está integrada.');
        }
        try {
            $p=$this->kernel->principalFor($userId);
            $allowed=$p&&$this->kernel->check($p,'campus.roles.manage')->allowed();
        } catch(\Throwable $e){report($e);throw new FinancialDependencyUnavailableException('La autorización histórica no está disponible.');}
        abort_unless($allowed,403,'La revisión histórica requiere una plaza global activa.');
    }
}
