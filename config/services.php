<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Integraciones externas
    |--------------------------------------------------------------------------
    |
    | Los valores sensibles se leen desde .env únicamente dentro de config/.
    | Los servicios y middleware deben consumirlos mediante config(), lo que
    | permite que funcionen correctamente después de php artisan config:cache.
    |
    */

    'team4' => [
        'shared_secret' => env('TEAM_SERVICE_SHARED_SECRET'),
        'allowed_clock_skew' => (int) env('TEAM_SERVICE_ALLOWED_CLOCK_SKEW', 300),
    ],

];
