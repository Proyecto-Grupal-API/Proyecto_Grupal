<?php

use App\Http\Controllers\Financial\AdministrativeReceiptWebController;
use App\Http\Controllers\Financial\LegacyCashReceiptHistoryController;
use App\Http\Controllers\Financial\LegacyCashReceiptReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'session.active', 'device.track', 'financial.correlation'])
    ->prefix('finanzas/comprobantes-administracion')->name('financial.receipts.admin.')->group(function () {
        Route::get('/', [AdministrativeReceiptWebController::class, 'index'])->name('index');
        Route::post('/legacy-reviews', [LegacyCashReceiptReviewController::class, 'store'])->middleware('throttle:10,1')->name('legacy.review');
        Route::get('/history', [LegacyCashReceiptHistoryController::class, 'index'])->name('history');
        Route::get('/history/records', [LegacyCashReceiptHistoryController::class, 'listing'])->middleware('throttle:30,1')->name('history.records');
        Route::get('/history/{reviewId}', [LegacyCashReceiptHistoryController::class, 'show'])->whereUuid('reviewId')->middleware('throttle:30,1')->name('history.show');
        Route::get('/pdf', [AdministrativeReceiptWebController::class, 'downloadPdf'])->middleware('throttle:20,1')->name('pdf');
        Route::get('/lookup', [AdministrativeReceiptWebController::class, 'show'])->middleware('throttle:30,1')->name('show');
    });
