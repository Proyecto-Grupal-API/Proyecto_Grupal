<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $connection = config('database.connections.mongodb');
        $connection['database'] = $this->app->environment('testing') ? 'campus_virtual_testing'
            : (config('team4.database') ?: $connection['database'].'_inventarios');
        config(['database.connections.inventory' => $connection]);
    }
}
