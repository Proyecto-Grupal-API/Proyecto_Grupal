<?php

use App\Http\Controllers\Api\Community\OrganizationSummaryController;
use App\Http\Controllers\Api\Identity\BusinessAuthorizationController;
use App\Http\Controllers\Api\Identity\NfcValidationController;
use App\Http\Controllers\Api\Identity\QrValidationController;
use App\Http\Controllers\OAuthTokenController;
use App\Http\Controllers\StudentServicesController;
use App\Http\Middleware\AuthorizeCommunityService;
use App\Http\Middleware\AuthorizeIdentityService;
use Illuminate\Support\Facades\Route;

foreach (['authorization/check' => ['post', 'check', 'identity:authorization:check'],
    'assignments' => ['get', 'assignments', 'identity:assignments:read'],
    'business-owner/provision' => ['post', 'provision', 'identity:business-owner:provision']] as $path => [$method, $action, $scope]) {
    Route::$method('/v1/identity/'.$path, [BusinessAuthorizationController::class, $action])
        ->middleware(['oauth.service:'.$scope, AuthorizeIdentityService::class.':'.$scope, 'throttle:30,1']);
}

Route::post('/oauth/token', OAuthTokenController::class)->middleware('throttle:60,1');

Route::get('/v1/organizations/{organizationId}/summary', OrganizationSummaryController::class)
    ->where('organizationId', '[a-fA-F0-9]{24}')
    ->middleware(['oauth.service:community:organizations:read', AuthorizeCommunityService::class.':community:organizations:read', 'throttle:120,1,community-organizations-summary:'])
    ->name('api.community.organizations.summary');

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


Route::prefix('v1/comunidad')->middleware([\App\Http\Middleware\CorrelacionAviso::class])->group(function () {
    foreach (['get' => 'read', 'post' => 'send'] as $method => $capacidad) {
        Route::$method('/notificaciones', [\App\Http\Controllers\Api\Community\AvisoController::class, $method === 'get' ? 'index' : 'store'])
            ->middleware(['oauth.service:notifications:'.$capacidad, AuthorizeCommunityService::class.':notifications:'.$capacidad, 'throttle:avisos-servicio']);
    }
    Route::post('/notificaciones/{id}/retiro', [\App\Http\Controllers\Api\Community\AvisoController::class, 'retiro'])
        ->where('id', '[a-fA-F0-9]{24}')
        ->middleware(['oauth.service:notifications:send', AuthorizeCommunityService::class.':notifications:send', 'throttle:avisos-servicio']);
});

require __DIR__.'/student-services-api.php';

require __DIR__.'/inventory-api.php';

Route::get('/v1/comunidad/organizaciones/comercio',\App\Http\Controllers\Api\Community\OrganizationCommerceController::class)
    ->middleware(['oauth.service:community:organizations:read',\App\Http\Middleware\AuthorizeCommunityService::class.':community:organizations:read','throttle:120,1']);

Route::prefix('v1/marketplace')->middleware(['oauth.service:marketplace:read',\App\Http\Middleware\AuthorizeCommunityService::class.':marketplace:read','throttle:120,1'])->group(function () {
    Route::get('catalog',[\App\Http\Controllers\Marketplace\MarketplaceController::class,'catalog']);
    Route::get('businesses',[\App\Http\Controllers\Marketplace\MarketplaceController::class,'businessesApi']);
});
