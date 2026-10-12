<?php

return [
    'name' => env('APP_NAME', 'Campus Digital'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => 'America/Mexico_City',
    'locale' => 'es',
    'fallback_locale' => 'es',
    'faker_locale' => 'es_MX',
    'key' => env('APP_KEY'),
    'cipher' => 'AES-256-CBC',
    'maintenance' => ['driver' => 'file'],
    'providers' => [
        App\Providers\AppServiceProvider::class,
        App\Providers\FortifyServiceProvider::class,
    ],
];
