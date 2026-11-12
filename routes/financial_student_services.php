<?php

use App\Http\Controllers\Financial\StudentServiceCheckoutController;
use App\Http\Middleware\RenderMarketplaceCheckoutFailures;
use Illuminate\Support\Facades\Route;

/*
 * Cobros de Servicios al Estudiante (E5): lockers e impresiones. Mismo
 * esquema que las multas de Biblioteca. Las garantías de renta tienen su
 * propio piloto (routes/financial_rental_deposit_pilot.php).
 */
Route::middleware([RenderMarketplaceCheckoutFailures::class, 'throttle:30,1'])
    ->prefix('api/v1/financial/student-services')->group(function () {
        $concept = 'locker_rental|print_order';

        Route::post('/payments/{concept}/{source}', [StudentServiceCheckoutController::class, 'prepare'])
            ->where(['concept' => $concept, 'source' => '[a-f0-9]{24}'])
            ->middleware('oauth.service:financial:student-service:prepare');
        Route::get('/payments/{concept}/{source}', [StudentServiceCheckoutController::class, 'lookup'])
            ->where(['concept' => $concept, 'source' => '[a-f0-9]{24}'])
            ->middleware('oauth.service:financial:student-service:read');
        Route::post('/payments/{concept}/{source}/cancel', [StudentServiceCheckoutController::class, 'cancel'])
            ->where(['concept' => $concept, 'source' => '[a-f0-9]{24}'])
            ->middleware('oauth.service:financial:student-service:cancel');
    });

Route::middleware(['web', 'auth', 'verified', 'session.active', 'device.track', RenderMarketplaceCheckoutFailures::class])
    ->prefix('finanzas/servicios-estudiante')->name('financial.student-services.')->group(function () {
        Route::get('/solicitudes/{paymentRequest}', [StudentServiceCheckoutController::class, 'show'])
            ->whereUuid('paymentRequest')->name('show');
        Route::post('/solicitudes/{paymentRequest}/confirmar', [StudentServiceCheckoutController::class, 'confirm'])
            ->whereUuid('paymentRequest')->withoutMiddleware('device.track')->middleware('throttle:10,1')->name('confirm');
    });
