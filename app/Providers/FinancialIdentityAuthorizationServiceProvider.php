<?php

namespace App\Providers;

use App\Domains\Financial\Adapters\{IdentityBonusAuthorizationProvider, PendingBonusAuthorizationProvider};
use App\Domains\Financial\Contracts\BonusAuthorizationProvider;
use Illuminate\Support\ServiceProvider;

final class FinancialIdentityAuthorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register after AppServiceProvider. Do not replace generic web/cash/transfer authorizers.
        $this->app->bind(BonusAuthorizationProvider::class, function ($app) {
            return config('financial_identity_authorization.enabled') === true
                ? $app->make(IdentityBonusAuthorizationProvider::class)
                : $app->make(PendingBonusAuthorizationProvider::class);
        });
    }
}
