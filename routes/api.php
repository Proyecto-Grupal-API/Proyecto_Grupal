<?php

use App\Http\Controllers\StudentServicesController;
use App\Http\Controllers\OAuthTokenController;
use App\Http\Controllers\Financial\WalletController;
use App\Http\Controllers\Financial\TopUpController;
use Illuminate\Support\Facades\Route;

Route::post('/oauth/token', OAuthTokenController::class)
    ->middleware('throttle:60,1');

Route::prefix('v1')->middleware('oauth.service')->group(function () {
    Route::get(
        '/students/{studentId}/status',
        [StudentServicesController::class, 'status']
    );

    Route::get(
        '/students/{studentId}/status/history',
        [StudentServicesController::class, 'statusHistory']
    );

    Route::get(
        '/students/{studentId}/consents',
        [StudentServicesController::class, 'consents']
    );

    Route::post(
        '/students/{studentId}/consents',
        [StudentServicesController::class, 'acceptConsent']
    );

    Route::delete(
        '/students/{studentId}/consents/{consentId}',
        [StudentServicesController::class, 'revokeConsent']
    );

    Route::get(
        '/students/{studentId}/preferences',
        [StudentServicesController::class, 'preferences']
    );

    Route::patch(
        '/students/{studentId}/preferences',
        [StudentServicesController::class, 'updatePreferences']
    );

    Route::prefix('financial')->group(function () {
        Route::middleware(
            'oauth.service:financial:read'
        )->group(function () {
            Route::get(
                '/wallets/{walletId}',
                [WalletController::class, 'show']
            );

            Route::get(
                '/wallets/{walletId}/ledger',
                [WalletController::class, 'ledger']
            );
        });

        Route::middleware(
            'oauth.service:financial:write'
        )->group(function () {
            Route::post(
                '/topups',
                [TopUpController::class, 'store']
            );

            Route::post(
                '/topups/{topUpId}/complete',
                [TopUpController::class, 'complete']
            );
        });
    });
});
