<?php

use App\Http\Controllers\Api\Identity\BusinessAuthorizationController;
use App\Http\Controllers\Api\Identity\FinancialAuthorizationController;
use App\Http\Controllers\Api\Identity\NfcValidationController;
use App\Http\Controllers\Api\Identity\QrValidationController;
use App\Http\Controllers\OAuthTokenController;
use App\Http\Controllers\StudentServicesController;
use App\Http\Middleware\AuthorizeIdentityService;
use Illuminate\Support\Facades\Route;

foreach (['authorization/check' => ['post', 'check', 'identity:authorization:check'],
    'assignments' => ['get', 'assignments', 'identity:assignments:read'],
    'business-owner/provision' => ['post', 'provision', 'identity:business-owner:provision']] as $path => [$method, $action, $scope]) {
    Route::$method('/v1/identity/'.$path, [BusinessAuthorizationController::class, $action])
        ->middleware(['oauth.service:'.$scope, AuthorizeIdentityService::class.':'.$scope, 'throttle:30,1']);
}

Route::post('/oauth/token', OAuthTokenController::class)->middleware('throttle:60,1');

Route::prefix('v1')->middleware('oauth.service:students:read')->group(function () {
    Route::get('/students/{studentId}/status', [StudentServicesController::class, 'status']);
    Route::get('/students/{studentId}/status/history', [StudentServicesController::class, 'statusHistory']);
});

Route::post('/v1/identity/qr-validate', QrValidationController::class)
    ->middleware(['oauth.service:identity:qr:validate', 'throttle:30,1']);

Route::post('/v1/identity/nfc-validate', NfcValidationController::class)
    ->middleware(['oauth.service:identity:nfc:validate', 'throttle:30,1']);

Route::prefix('v1')->middleware(['auth:sanctum', 'initial.password'])->group(function () {
    Route::get('/students/{studentId}/consents', [StudentServicesController::class, 'consents']);
    Route::post('/students/{studentId}/consents', [StudentServicesController::class, 'acceptConsent']);
    Route::delete('/students/{studentId}/consents/{consentId}', [StudentServicesController::class, 'revokeConsent']);
    Route::get('/students/{studentId}/preferences', [StudentServicesController::class, 'preferences']);
    Route::patch('/students/{studentId}/preferences', [StudentServicesController::class, 'updatePreferences']);
    Route::patch('/students/{studentId}/status', [StudentServicesController::class, 'changeStatus']);
});

// LOCAL ONLY: D1-D4 require explicit directory policy; no consumers are enabled by default.
Route::prefix('v1/identity/directory')->middleware([
    \App\Http\Middleware\DirectoryTransport::class,
    'oauth.service:,directory',
])->group(function () {
    Route::post('/resolve-enrollment', [\App\Http\Controllers\Api\Identity\IdentityDirectoryController::class, 'resolve'])
        ->middleware(\App\Http\Middleware\DirectoryAccess::class.':resolve');
    Route::post('/batch', [\App\Http\Controllers\Api\Identity\IdentityDirectoryController::class, 'batch'])
        ->middleware(\App\Http\Middleware\DirectoryAccess::class.':batch');
});

Route::post('/v1/identity/financial-authorization/check', [FinancialAuthorizationController::class, 'check'])
    ->middleware(['oauth.service:identity:financial-authorization:check', AuthorizeIdentityService::class.':identity:financial-authorization:check', 'throttle:30,1']);
