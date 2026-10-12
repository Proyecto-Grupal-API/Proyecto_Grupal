<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Financial\AdministrativeReceiptWebController;
Route::middleware(['web','auth','verified','session.active','device.track','financial.correlation'])
    ->prefix('finanzas/comprobantes-administracion')->name('financial.receipts.admin.')->group(function(){
        Route::get('/',[AdministrativeReceiptWebController::class,'index'])->name('index');
        Route::post('/legacy-reviews',[\App\Http\Controllers\Financial\LegacyCashReceiptReviewController::class,'store'])->middleware('throttle:10,1')->name('legacy.review');
        Route::get('/history',[\App\Http\Controllers\Financial\LegacyCashReceiptHistoryController::class,'index'])->name('history');
        Route::get('/history/records',[\App\Http\Controllers\Financial\LegacyCashReceiptHistoryController::class,'listing'])->middleware('throttle:30,1')->name('history.records');
        Route::get('/history/{reviewId}',[\App\Http\Controllers\Financial\LegacyCashReceiptHistoryController::class,'show'])->whereUuid('reviewId')->middleware('throttle:30,1')->name('history.show');
        Route::get('/pdf',[AdministrativeReceiptWebController::class,'downloadPdf'])->middleware('throttle:20,1')->name('pdf');
        Route::get('/lookup' ,[AdministrativeReceiptWebController::class,'show'])->middleware('throttle:30,1')->name('show');
    });
