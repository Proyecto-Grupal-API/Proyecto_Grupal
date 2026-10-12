<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Financial\ReceiptDelegationConsentController;
Route::middleware(['web','auth','verified','session.active','device.track',\App\Http\Middleware\RenderReceiptConsentFailures::class])
    ->prefix('finanzas/comprobantes-api')->name('financial.receipts.consent.')->group(function(){
        Route::get('/',[ReceiptDelegationConsentController::class,'index'])->name('index');
        Route::get('/records',[ReceiptDelegationConsentController::class,'listing'])->name('records');
        Route::post('/',[ReceiptDelegationConsentController::class,'issue'])->middleware('throttle:10,1')->name('issue');
        Route::post('/{grantId}/revoke',[ReceiptDelegationConsentController::class,'revoke'])->whereUuid('grantId')->name('revoke');
    });
