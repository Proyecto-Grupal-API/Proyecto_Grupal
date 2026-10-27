<?php

use App\Http\Controllers\Financial\MarketplaceCheckoutWebController;
use App\Http\Controllers\Financial\MarketplacePaymentRequestController;
use App\Http\Middleware\RenderMarketplaceCheckoutFailures;
use Illuminate\Support\Facades\Route;

Route::middleware([RenderMarketplaceCheckoutFailures::class, 'throttle:30,1'])->prefix('api/v1/financial/marketplace')->group(function () {
    Route::post('/payment-requests', [MarketplacePaymentRequestController::class, 'prepare'])->middleware('oauth.service:financial:purchase:prepare');
    Route::get('/payment-requests/by-order', [MarketplacePaymentRequestController::class, 'lookup'])->middleware('oauth.service:financial:purchase:read');
});
Route::middleware(['web', 'auth', 'verified', 'session.active', 'device.track', RenderMarketplaceCheckoutFailures::class])
    ->prefix('finanzas/compras')->name('financial.marketplace.')->group(function () {
        Route::get('/', [MarketplaceCheckoutWebController::class, 'index'])->name('index');
        Route::get('/solicitudes/{paymentRequest}', [MarketplaceCheckoutWebController::class, 'show'])->whereUuid('paymentRequest')->name('show');
        Route::post('/solicitudes/{paymentRequest}/confirmar', [MarketplaceCheckoutWebController::class, 'confirm'])->whereUuid('paymentRequest')->middleware('throttle:10,1')->name('confirm');
        Route::post('/solicitudes/{paymentRequest}/cancelar', [MarketplaceCheckoutWebController::class, 'cancel'])->whereUuid('paymentRequest')->middleware('throttle:10,1')->name('cancel');
    });
