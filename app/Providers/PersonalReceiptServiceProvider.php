<?php

namespace App\Providers;

use App\Domains\Financial\Adapters\PendingLegacyCashReceiptAuthorization;
use App\Domains\Financial\Adapters\PendingReceiptAdministrativeAuthorization;
use App\Domains\Financial\Adapters\PendingReceiptDelegationProvider;
use App\Domains\Financial\Contracts\LegacyCashReceiptAuthorization;
use App\Domains\Financial\Contracts\ReceiptAdministrativeAuthorization;
use App\Domains\Financial\Contracts\ReceiptDelegationProvider;
use Illuminate\Support\ServiceProvider;

class PersonalReceiptServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LegacyCashReceiptAuthorization::class,
            PendingLegacyCashReceiptAuthorization::class);
        $this->app->bind(ReceiptAdministrativeAuthorization::class,
            PendingReceiptAdministrativeAuthorization::class);
        $this->app->bind(ReceiptDelegationProvider::class, PendingReceiptDelegationProvider::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/financial_receipts.php'));
        $this->loadRoutesFrom(base_path('routes/financial_receipts_api.php'));
        $this->loadRoutesFrom(base_path('routes/receipt_api_consents.php'));
        $this->loadRoutesFrom(base_path('routes/financial_receipts_administration.php'));
    }
}
