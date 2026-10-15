<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    App\Providers\InventoryServiceProvider::class,
    App\Providers\MarketplaceServiceProvider::class,
    App\Providers\StudentServicesServiceProvider::class,
    AppServiceProvider::class,
    FortifyServiceProvider::class,
];
