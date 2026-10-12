<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use App\Domains\Financial\Contracts\ReceiptDelegationProvider;
use App\Domains\Financial\Adapters\PendingReceiptDelegationProvider;
class PersonalReceiptServiceProvider extends ServiceProvider
{
    public function register(): void {
        $this->app->bind(\App\Domains\Financial\Contracts\LegacyCashReceiptAuthorization::class,
            \App\Domains\Financial\Adapters\CampusLegacyCashReceiptAuthorization::class);
        $this->app->bind(\App\Domains\Financial\Contracts\ReceiptAdministrativeAuthorization::class, function ($app) {
            return match(config('financial_receipts.administration_provider','pending')) {
                'campus'=>$app->make(\App\Domains\Financial\Adapters\CampusReceiptAdministrativeAuthorization::class),
                'pending'=>$app->make(\App\Domains\Financial\Adapters\PendingReceiptAdministrativeAuthorization::class),
                default=>throw new \Symfony\Component\HttpKernel\Exception\HttpException(503,'Proveedor administrativo inválido.'),
            };
        });
        $this->app->bind(ReceiptDelegationProvider::class, fn ($app) =>
            $app->make(config('financial_receipts.delegation_provider', PendingReceiptDelegationProvider::class)));
    }
    public function boot(): void {
        $this->loadRoutesFrom(base_path('routes/financial_receipts.php'));
        $this->loadRoutesFrom(base_path('routes/financial_receipts_api.php'));
        $this->loadRoutesFrom(base_path('routes/financial_receipts_administration.php'));
    }
}
