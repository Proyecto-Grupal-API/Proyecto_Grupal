<?php

use App\Http\Controllers\Financial\EventCheckoutController;
use App\Http\Middleware\RenderMarketplaceCheckoutFailures;
use Illuminate\Support\Facades\Route;

Route::middleware([RenderMarketplaceCheckoutFailures::class, 'throttle:30,1'])
    ->prefix('api/v1/financial/community/event-reservations/{registroId}/generations/{generation}/payment')
    ->where(['registroId' => '[a-f0-9]{24}', 'generation' => '[1-9][0-9]{0,8}'])->group(function () {
        Route::post('/', [EventCheckoutController::class, 'prepare'])->middleware('oauth.service:financial:event-payment:prepare');
        Route::get('/', [EventCheckoutController::class, 'lookup'])->middleware('oauth.service:financial:event-payment:read');
        Route::post('/cancel', [EventCheckoutController::class, 'cancel'])->middleware('oauth.service:financial:event-payment:cancel');
    });

Route::middleware(['web', 'auth', 'verified', 'session.active', 'device.track', RenderMarketplaceCheckoutFailures::class])
    ->prefix('finanzas/eventos')->name('financial.event-payments.')->group(function () {
        Route::get('/solicitudes/{paymentRequest}', [EventCheckoutController::class, 'show'])->whereUuid('paymentRequest')->name('show');
        Route::post('/solicitudes/{paymentRequest}/confirmar', [EventCheckoutController::class, 'confirm'])
            ->whereUuid('paymentRequest')->withoutMiddleware('device.track')->middleware('throttle:10,1');
    });
