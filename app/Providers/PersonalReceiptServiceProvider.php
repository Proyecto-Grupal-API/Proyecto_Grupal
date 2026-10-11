<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use App\Domains\Financial\Contracts\ReceiptDelegationProvider;
use App\Domains\Financial\Adapters\PendingReceiptDelegationProvider;
class PersonalReceiptServiceProvider extends ServiceProvider
{
    public function register(): void {
        $this->app->bind(ReceiptDelegationProvider::class, fn ($app) =>
            $app->make(config('financial_receipts.delegation_provider', PendingReceiptDelegationProvider::class)));
    }
    public function boot(): void {
        $this->loadRoutesFrom(base_path('routes/financial_receipts.php'));
        $this->loadRoutesFrom(base_path('routes/financial_receipts_api.php'));
    }
}
