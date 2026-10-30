<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Aquí se registran las rutas para la API. Estas rutas son
| automáticamente asignadas al grupo de middleware "api".
|
*/

// Webhook receptor para eventos del Equipo 7 (sin CSRF, usa HMAC)
Route::prefix('v1/integrations')->middleware([
    \App\Http\Middleware\Team4ServiceSignature::class,
    'skip.csrf', // Desactiva la verificación CSRF para esta ruta
])->group(function () {
    Route::post('/eq7-events', [\App\Api\Team7WebhookController::class, 'receive']);
});