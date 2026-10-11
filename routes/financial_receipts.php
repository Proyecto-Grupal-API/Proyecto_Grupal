<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Financial\PersonalReceiptWebController;
Route::middleware(['web', 'auth', 'verified', 'session.active', 'device.track', 'financial.correlation'])
    ->prefix('finanzas/comprobantes')->name('financial.receipts.')->group(function () {
        Route::get('/', [PersonalReceiptWebController::class, 'index'])->name('index');
        Route::get('/{source}/{operationId}', [PersonalReceiptWebController::class, 'show'])
            ->whereIn('source', ['transaction', 'purchase'])->whereUuid('operationId')->name('show');
    });
