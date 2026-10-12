<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use App\Domains\Financial\Contracts\ReceiptDelegationProvider;
use App\Domains\Financial\Adapters\PendingReceiptDelegationProvider;
class PersonalReceiptServiceProvider extends ServiceProvider
{
    public function register(): void {
        $this->app->bind(\App\Domains\Financial\Contracts\LegacyCashReceiptAuthorization::class,
            \App\Domains\Financial\Adapters\PendingLegacyCashReceiptAuthorization::class);
        $this->app->bind(\App\Domains\Financial\Contracts\ReceiptAdministrativeAuthorization::class,
            \App\Domains\Financial\Adapters\PendingReceiptAdministrativeAuthorization::class);
        $this->app->bind(ReceiptDelegationProvider::class, fn ($app) =>
             $app->make(config('financial_receipts.delegation_provider')==='local' ? \App\Domains\Identity\ReceiptDelegation\LocalReceiptDelegationProvider::class : config('financial_receipts.delegation_provider', PendingReceiptDelegationProvider::class)));
    }
    public function boot(): void {
        $this->loadRoutesFrom(base_path('routes/financial_receipts.php'));
        $this->loadRoutesFrom(base_path('routes/financial_receipts_api.php'));
        $this->loadRoutesFrom(base_path('routes/receipt_api_consents.php'));
        $this->loadRoutesFrom(base_path('routes/financial_receipts_administration.php'));
    }
}
