<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Financial\AdministrativeReceiptWebController;
Route::middleware(['web','auth','verified','session.active','device.track','financial.correlation'])
    ->prefix('finanzas/comprobantes-administracion')->name('financial.receipts.admin.')->group(function(){
        Route::get('/',[AdministrativeReceiptWebController::class,'index'])->name('index');
        Route::post('/legacy-reviews',[\App\Http\Controllers\Financial\LegacyCashReceiptReviewController::class,'store'])->middleware('throttle:10,1')->name('legacy.review');
        Route::get('/lookup',[AdministrativeReceiptWebController::class,'show'])->middleware('throttle:30,1')->name('show');
    });
