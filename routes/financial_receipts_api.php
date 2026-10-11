<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Financial\PersonalReceiptApiController;
use App\Http\Middleware\ResolveReceiptDelegation;
Route::middleware(['api','oauth.service:financial:receipt:read','financial.correlation','throttle:30,1'])
    ->prefix('api/v1/financial/receipts')->name('financial.receipts.api.')->group(function () {
        Route::get('/',[PersonalReceiptApiController::class,'index'])
            ->middleware(ResolveReceiptDelegation::class.':receipt.list')->name('index');
        Route::get('/{source}/{operationId}',[PersonalReceiptApiController::class,'show'])
            ->whereIn('source',['transaction','purchase'])->whereUuid('operationId')
            ->middleware(ResolveReceiptDelegation::class.':receipt.read')->name('show');
    });
