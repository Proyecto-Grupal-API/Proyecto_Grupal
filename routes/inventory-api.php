<?php
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\InventoryModule\Team4ServiceSignature;
use App\Http\Controllers\InventoryModule\Team4Controller;
use App\Api\InventoryModule\Team3IntegrationController;

Route::prefix('equipo4')->middleware([Team4ServiceSignature::class.':equipo3', 'throttle:120,1'])->group(function () {
    foreach (['products', 'warehouses', 'locations', 'inventory', 'movements', 'suppliers', 'purchase-orders'] as $path) {
        Route::get('/'.$path, [Team4Controller::class, ['purchase-orders' => 'purchaseOrders'][$path] ?? $path]);
    }
    Route::prefix('integration')->group(function () {
        Route::post('/availability', [Team3IntegrationController::class, 'availability']);
        Route::post('/reservations', [Team3IntegrationController::class, 'reserve']);
        Route::get('/reservations/{id}', [Team3IntegrationController::class, 'show']);
        Route::post('/reservations/{id}/confirm', [Team3IntegrationController::class, 'confirm']);
        Route::post('/reservations/{id}/release', [Team3IntegrationController::class, 'release']);
        Route::post('/returns', [\App\Api\InventoryModule\InventoryReturnController::class, 'receive']);
        Route::post('/transfers', [\App\Api\InventoryModule\InventoryTransferController::class, 'receive']);
        Route::post('/adjustments', [\App\Api\InventoryModule\InventoryAdjustmentController::class, 'receive']);
    });
});
Route::post('/equipo4/webhooks/payment-status', [\App\Api\InventoryModule\PaymentWebhookController::class, 'receive'])
    ->middleware([Team4ServiceSignature::class.':equipo2', 'throttle:120,1']);
Route::prefix('v1/inventory')->middleware([Team4ServiceSignature::class.':equipo7', 'throttle:120,1'])->group(function () {
    $c=\App\Api\InventoryModule\Team7ApiController::class;
    Route::match(['GET','POST'], '/availability', [$c, 'availability']);
    Route::post('/reservations', [$c, 'reserve']);
    Route::get('/reservations/{id}', [$c, 'show']);
    Route::post('/reservations/{id}/confirm', [$c, 'confirm']);
    Route::post('/reservations/{id}/release', [$c, 'release']);
});

// E3's multi-business bridge. Context is signed in the path, never selected by an unsigned header.
Route::post('equipo4/integration/businesses',[\App\Api\InventoryModule\MarketplaceBridgeController::class,'register'])
    ->middleware([Team4ServiceSignature::class.':equipo3,registration','throttle:120,1']);
Route::prefix('equipo4/businesses/{businessId}')->middleware([Team4ServiceSignature::class.':equipo3,scoped','throttle:120,1'])->group(function () {
    foreach (['products','warehouses','locations','inventory'] as $path) { Route::get('/'.$path,[Team4Controller::class,$path]); }
    Route::post('/integration/reservation-status',[\App\Api\InventoryModule\MarketplaceBridgeController::class,'operation']);
    Route::post('/integration/availability',[Team3IntegrationController::class,'availability']);
    Route::post('/integration/reservations',[Team3IntegrationController::class,'reserve']);
    Route::get('/integration/reservations/{id}',[Team3IntegrationController::class,'show']);
    Route::post('/integration/reservations/{id}/release',[Team3IntegrationController::class,'release']);
    Route::post('/integration/reservations/{id}/confirm',[Team3IntegrationController::class,'confirm']);
});
