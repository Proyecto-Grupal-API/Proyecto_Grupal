<?php

use App\Http\Controllers\Financial\LibraryFineCheckoutController;
use App\Http\Middleware\RenderMarketplaceCheckoutFailures;
use Illuminate\Support\Facades\Route;

Route::middleware([RenderMarketplaceCheckoutFailures::class, 'throttle:30,1'])
    ->prefix('api/v1/financial/student-services/library-fines')->group(function () {
        Route::post('/{fine}', [LibraryFineCheckoutController::class, 'prepare'])->where('fine', '[a-f0-9]{24}')
            ->middleware('oauth.service:financial:library-fine:prepare');
        Route::get('/{fine}', [LibraryFineCheckoutController::class, 'lookup'])->where('fine', '[a-f0-9]{24}')
            ->middleware('oauth.service:financial:library-fine:read');
        Route::post('/{fine}/cancel', [LibraryFineCheckoutController::class, 'cancel'])->where('fine', '[a-f0-9]{24}')
            ->middleware('oauth.service:financial:library-fine:cancel');
    });

Route::middleware(['web', 'auth', 'verified', 'session.active', 'device.track', RenderMarketplaceCheckoutFailures::class])
    ->prefix('finanzas/multas-biblioteca')->name('financial.library-fines.')->group(function () {
        Route::get('/solicitudes/{paymentRequest}', [LibraryFineCheckoutController::class, 'show'])
            ->whereUuid('paymentRequest')->name('show');
        Route::post('/solicitudes/{paymentRequest}/confirmar', [LibraryFineCheckoutController::class, 'confirm'])
            ->whereUuid('paymentRequest')->withoutMiddleware('device.track')->middleware('throttle:10,1')->name('confirm');
    });
