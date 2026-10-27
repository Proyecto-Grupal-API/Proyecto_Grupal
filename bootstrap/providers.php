<?php

use App\Providers\AppServiceProvider;
use App\Providers\FinancialIdentityAuthorizationServiceProvider;
use App\Providers\FinancialServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\InventoryServiceProvider;
use App\Providers\MarketplaceCheckoutServiceProvider;
use App\Providers\MarketplaceServiceProvider;
use App\Providers\PersonalReceiptServiceProvider;
use App\Providers\PlatformServiceProvider;
use App\Providers\StudentServicesServiceProvider;

return [
    FinancialServiceProvider::class,
    PersonalReceiptServiceProvider::class,
    MarketplaceCheckoutServiceProvider::class,
    FinancialIdentityAuthorizationServiceProvider::class,
    PlatformServiceProvider::class,
    InventoryServiceProvider::class,
    MarketplaceServiceProvider::class,
    StudentServicesServiceProvider::class,
    AppServiceProvider::class,
    FortifyServiceProvider::class,
];
