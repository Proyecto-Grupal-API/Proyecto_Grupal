<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class MarketplaceCheckoutServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/financial_marketplace_checkout.php'));
    }
}
