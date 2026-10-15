<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class MarketplaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $connection = config('database.connections.mongodb');
        $connection['database'] = $this->app->environment('testing') ? 'campus_virtual_testing' : (config('marketplace.database') ?: $connection['database'].'_marketplace');
        config(['database.connections.marketplace' => $connection]);
    }
}
