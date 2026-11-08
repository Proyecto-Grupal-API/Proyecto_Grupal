<?php

namespace App\Providers;

use App\Domains\Financial\Contracts\InstitutionalBudgetAuthorization;
use App\Domains\Financial\Adapters\PendingInstitutionalBudgetAuthorization;
use Illuminate\Support\ServiceProvider;

final class InstitutionalBudgetServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/financial_institutional_read.php'));
    }

    public function register(): void
    {
        $this->app->bind(\App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization::class, \App\Domains\Financial\Adapters\PendingInstitutionalSettlementAuthorization::class);
        $this->app->bind(\App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization::class, \App\Domains\Financial\Adapters\PendingInstitutionalPurchaseAuthorization::class);
        $this->app->bind(InstitutionalBudgetAuthorization::class, PendingInstitutionalBudgetAuthorization::class);
    }
}
