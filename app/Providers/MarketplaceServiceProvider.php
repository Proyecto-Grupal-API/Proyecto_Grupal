<?php

namespace App\Providers;

use App\Models\Marketplace\Order;
use App\Observers\MarketplaceOrderRewardsObserver;
use Illuminate\Support\ServiceProvider;

class MarketplaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $connection = config('database.connections.mongodb');
        $connection['database'] = $this->app->environment('testing') ? 'campus_virtual_testing' : (config('marketplace.database') ?: $connection['database'].'_marketplace');
        config(['database.connections.marketplace' => $connection]);
    }

    public function boot(): void
    {
        // Ventas confirmadas y devoluciones aprobadas se avisan a Recompensas (sólo si la bandera está encendida).
        Order::observe(MarketplaceOrderRewardsObserver::class);
    }
}
