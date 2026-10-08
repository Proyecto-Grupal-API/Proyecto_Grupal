<?php

namespace App\Providers;

use App\Services\Integrations\MockTeam1IdentityGateway;
use App\Services\Integrations\MockTeam2PaymentGateway;
use App\Services\Integrations\MockTeam4InventoryGateway;
use App\Services\Integrations\MockTeam7RewardsGateway;
use App\Services\Integrations\Team1IdentityGateway;
use App\Services\Integrations\Team2PaymentGateway;
use App\Services\Integrations\Team4InventoryGateway;
use App\Services\Integrations\Team7RewardsGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Team1IdentityGateway::class, MockTeam1IdentityGateway::class);
        $this->app->bind(Team2PaymentGateway::class, MockTeam2PaymentGateway::class);
        $this->app->bind(Team4InventoryGateway::class, MockTeam4InventoryGateway::class);
        $this->app->bind(Team7RewardsGateway::class, MockTeam7RewardsGateway::class);
    }

    public function boot(): void
    {
        //
    }
}
