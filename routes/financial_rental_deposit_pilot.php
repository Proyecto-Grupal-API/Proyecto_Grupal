<?php

use App\Http\Controllers\Financial\RentalDepositPilotController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:30,1')
    ->prefix('api/v1/financial/student-services/rentals/{rental}/deposit')
    ->where(['rental' => '[a-f0-9]{24}'])
    ->group(function () {
        Route::post('/', [RentalDepositPilotController::class, 'hold'])
            ->middleware('oauth.service:financial:rental-deposit:hold');
        Route::get('/', [RentalDepositPilotController::class, 'lookup'])
            ->middleware('oauth.service:financial:rental-deposit:hold');
        Route::post('/{hold}/settle', [RentalDepositPilotController::class, 'settle'])
            ->whereUuid('hold')->middleware('oauth.service:financial:rental-deposit:settle');
    });
