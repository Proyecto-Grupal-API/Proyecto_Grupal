<?php

use App\Http\Controllers\Financial\PersonalReceiptWebController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'session.active', 'device.track', 'financial.correlation'])
    ->prefix('finanzas/comprobantes')->name('financial.receipts.')->group(function () {
        Route::get('/', [PersonalReceiptWebController::class, 'index'])->name('index');
        Route::get('/{source}/{operationId}/pdf', [PersonalReceiptWebController::class, 'downloadPdf'])
            ->whereIn('source', ['transaction', 'purchase'])->whereUuid('operationId')->middleware('throttle:20,1')->name('pdf');
        Route::get('/{source}/{operationId}', [PersonalReceiptWebController::class, 'show'])
            ->whereIn('source', ['transaction', 'purchase'])->whereUuid('operationId')->name('show');
    });
