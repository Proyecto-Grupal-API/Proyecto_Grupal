<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $connection = config('database.connections.mongodb');
        $connection['database'] = $this->app->environment('testing') ? 'campus_virtual_testing' : (config('platform.database') ?: $connection['database'].'_plataforma');
        config(['database.connections.platform' => $connection]);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/platform.php'));
    }
}
