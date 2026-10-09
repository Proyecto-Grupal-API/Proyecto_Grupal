<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use MongoDB\Laravel\MongoDBServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    MongoDBServiceProvider::class,
];
