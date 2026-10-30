<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Team4Controller;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\KardexController;
use App\Http\Controllers\CostController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\ReorderRuleController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\StockCountController;
use App\Http\Middleware\EnsureTeam4Role;
use App\Http\Controllers\CategoryController;

// Pagina publica de "No autorizado"
Route::get('/equipo4/not-authorized', function (\Illuminate\Http\Request $request) {
    return \Inertia\Inertia::render('Equipo4/NotAuthorized', [
        'reason'     => (string) $request->query('reason', 'unknown'),
        'permission' => (string) $request->query('permission', ''),
    ]);
})->name('equipo4.not-authorized');

// Rutas DEV (solo local)
if (app()->environment('local', 'development', 'testing')) {
    Route::get('/equipo4/dev/switch/{userId}', function (string $userId) {
        session(['team4_impersonate_user_id' => $userId]);
        return redirect()->route('equipo4.dashboard');
    });
    Route::post('/equipo4/dev/clear-user', function () {
        session()->forget('team4_impersonate_user_id');
        return redirect()->route('equipo4.dashboard');
    });
}

// Panel admin protegido por rol + permisos finos
Route::prefix('equipo4')->middleware([EnsureTeam4Role::class])->group(function () {

    // ---------- Dashboard (visible para todos los roles autorizados) ----------
    Route::get('/', [Team4Controller::class, 'dashboard'])
        ->name('equipo4.dashboard');

    // ---------- Productos ----------
    Route::get('/productos', [ProductController::class, 'index'])
        ->middleware('team4.permission:productos.view')
        ->name('equipo4.productos.index');
    Route::post('/productos', [ProductController::class, 'store'])
        ->middleware('team4.permission:productos.create')
        ->name('equipo4.productos.store');
    Route::put('/productos/{id}', [ProductController::class, 'update'])
        ->middleware('team4.permission:productos.update')
        ->name('equipo4.productos.update');
    Route::delete('/productos/{id}', [ProductController::class, 'destroy'])
        ->middleware('team4.permission:productos.delete')
        ->name('equipo4.productos.destroy');

    // ---------- Souvenirs ----------
    Route::get('/souvenirs', [ProductController::class, 'souvenirs'])
        ->middleware('team4.permission:souvenirs.view')
        ->name('equipo4.souvenirs.index');
    Route::post('/souvenirs', [ProductController::class, 'store'])
        ->middleware('team4.permission:souvenirs.create')
        ->name('equipo4.souvenirs.store');
    Route::put('/souvenirs/{id}', [ProductController::class, 'update'])
        ->middleware('team4.permission:souvenirs.update')
        ->name('equipo4.souvenirs.update');
    Route::delete('/souvenirs/{id}', [ProductController::class, 'destroy'])
        ->middleware('team4.permission:souvenirs.delete')
        ->name('equipo4.souvenirs.destroy');

    // ---------- Proveedores ----------
    Route::get('/proveedores', [SupplierController::class, 'index'])
        ->middleware('team4.permission:proveedores.view')
        ->name('equipo4.proveedores.index');
    Route::post('/proveedores', [SupplierController::class, 'store'])
        ->middleware('team4.permission:proveedores.create')
        ->name('equipo4.proveedores.store');
    Route::put('/proveedores/{id}', [SupplierController::class, 'update'])
        ->middleware('team4.permission:proveedores.update')
        ->name('equipo4.proveedores.update');
    Route::delete('/proveedores/{id}', [SupplierController::class, 'destroy'])
        ->middleware('team4.permission:proveedores.delete')
        ->name('equipo4.proveedores.destroy');

    // ---------- Almacenes ----------
    Route::get('/almacenes', [WarehouseController::class, 'index'])
        ->middleware('team4.permission:almacenes.view')
        ->name('equipo4.almacenes.index');
    Route::post('/almacenes', [WarehouseController::class, 'store'])
        ->middleware('team4.permission:almacenes.create')
        ->name('equipo4.almacenes.store');
    Route::put('/almacenes/{id}', [WarehouseController::class, 'update'])
        ->middleware('team4.permission:almacenes.update')
        ->name('equipo4.almacenes.update');
    Route::delete('/almacenes/{id}', [WarehouseController::class, 'destroy'])
        ->middleware('team4.permission:almacenes.delete')
        ->name('equipo4.almacenes.destroy');

    // ---------- Categorias (mapea a productos.*) ----------
    Route::get('/categorias', [CategoryController::class, 'index'])
        ->middleware('team4.permission:productos.view')
        ->name('equipo4.categorias.index');
    Route::post('/categorias', [CategoryController::class, 'store'])
        ->middleware('team4.permission:productos.create')
        ->name('equipo4.categorias.store');
    Route::put('/categorias/{id}', [CategoryController::class, 'update'])
        ->middleware('team4.permission:productos.update')
        ->name('equipo4.categorias.update');
    Route::delete('/categorias/{id}', [CategoryController::class, 'destroy'])
        ->middleware('team4.permission:productos.delete')
        ->name('equipo4.categorias.destroy');

    // ---------- Ubicaciones (mapea a almacenes.*) ----------
    Route::post('/ubicaciones', [LocationController::class, 'store'])
        ->middleware('team4.permission:almacenes.update')
        ->name('equipo4.ubicaciones.store');
    Route::put('/ubicaciones/{id}', [LocationController::class, 'update'])
        ->middleware('team4.permission:almacenes.update')
        ->name('equipo4.ubicaciones.update');
    Route::delete('/ubicaciones/{id}', [LocationController::class, 'destroy'])
        ->middleware('team4.permission:almacenes.delete')
        ->name('equipo4.ubicaciones.destroy');

    // ---------- Compras ----------
    Route::get('/compras', [PurchaseOrderController::class, 'index'])
        ->middleware('team4.permission:compras.view')
        ->name('equipo4.compras.index');
    Route::post('/compras', [PurchaseOrderController::class, 'store'])
        ->middleware('team4.permission:compras.create')
        ->name('equipo4.compras.store');
    Route::get('/compras/{id}/details', [PurchaseOrderController::class, 'show'])
        ->middleware('team4.permission:compras.view')
        ->name('equipo4.compras.show');
    Route::put('/compras/{id}', [PurchaseOrderController::class, 'update'])
        ->middleware('team4.permission:compras.update')
        ->name('equipo4.compras.update');
    Route::patch('/compras/{id}/status', [PurchaseOrderController::class, 'changeStatus'])
        ->middleware('team4.permission:compras.authorize')
        ->name('equipo4.compras.status');
    Route::delete('/compras/{id}', [PurchaseOrderController::class, 'destroy'])
        ->middleware('team4.permission:compras.cancel')
        ->name('equipo4.compras.destroy');

    // ---------- Recepciones ----------
    Route::get('/recepciones', [GoodsReceiptController::class, 'index'])
        ->middleware('team4.permission:recepciones.view')
        ->name('equipo4.recepciones.index');
    Route::post('/recepciones', [GoodsReceiptController::class, 'store'])
        ->middleware('team4.permission:recepciones.create')
        ->name('equipo4.recepciones.store');
    Route::delete('/recepciones/{id}', [GoodsReceiptController::class, 'destroy'])
        ->middleware('team4.permission:recepciones.create')
        ->name('equipo4.recepciones.destroy');

    // ---------- Kardex ----------
    Route::get('/kardex', [KardexController::class, 'index'])
        ->middleware('team4.permission:kardex.view')
        ->name('equipo4.kardex.index');

    // ---------- Costos ----------
    Route::get('/costos', [CostController::class, 'index'])
        ->middleware('team4.permission:costos.view')
        ->name('equipo4.costos.index');
    Route::post('/costos', [CostController::class, 'store'])
        ->middleware('team4.permission:costos.register')
        ->name('equipo4.costos.store');
    Route::get('/costos/{id}/history', [CostController::class, 'history'])
        ->middleware('team4.permission:costos.view')
        ->name('equipo4.costos.history');

    // ---------- Reservas ----------
    Route::get('/reservas', [ReservationController::class, 'index'])
        ->middleware('team4.permission:reservas.view')
        ->name('equipo4.reservas.index');
    Route::post('/reservas', [ReservationController::class, 'store'])
        ->middleware('team4.permission:reservas.create')
        ->name('equipo4.reservas.store');
    Route::post('/reservas/{id}/release', [ReservationController::class, 'release'])
        ->middleware('team4.permission:reservas.release')
        ->name('equipo4.reservas.release');
    Route::post('/reservas/expire-overdue', [ReservationController::class, 'expireOverdue'])
        ->middleware('team4.permission:reservas.release')
        ->name('equipo4.reservas.expireOverdue');

    // ---------- Alertas ----------
    Route::get('/alertas', [AlertController::class, 'index'])
        ->middleware('team4.permission:alertas.view')
        ->name('equipo4.alertas.index');
    Route::post('/alertas/generate', [AlertController::class, 'generate'])
        ->middleware('team4.permission:alertas.sync')
        ->name('equipo4.alertas.generate');
    Route::post('/alertas/{id}/discard', [AlertController::class, 'discard'])
        ->middleware('team4.permission:alertas.discard')
        ->name('equipo4.alertas.discard');

    // ---------- Reglas de reorden (mapea a productos.update) ----------
    Route::post('/reglas-reorden/sync-all', [ReorderRuleController::class, 'syncAll'])
        ->middleware('team4.permission:productos.update')
        ->name('equipo4.reglas.syncAll');
    Route::put('/reglas-reorden/{id}', [ReorderRuleController::class, 'update'])
        ->middleware('team4.permission:productos.update')
        ->name('equipo4.reglas.update');
    Route::post('/reglas-reorden/{id}/reset', [ReorderRuleController::class, 'reset'])
        ->middleware('team4.permission:productos.update')
        ->name('equipo4.reglas.reset');

    // ---------- Inventario ----------
    Route::get('/inventario', [InventoryController::class, 'index'])
        ->middleware('team4.permission:inventario.view')
        ->name('equipo4.inventario.index');
    Route::post('/inventario/adjust', [InventoryController::class, 'adjust'])
        ->middleware('team4.permission:inventario.adjust')
        ->name('equipo4.inventario.adjust');

    // ---------- Devoluciones ----------
    Route::get('/devoluciones', [ReturnController::class, 'index'])
        ->middleware('team4.permission:devoluciones.view')
        ->name('equipo4.devoluciones.index');
    Route::post('/devoluciones/proveedor', [ReturnController::class, 'storeSupplier'])
        ->middleware('team4.permission:devoluciones.storeSupplier')
        ->name('equipo4.devoluciones.storeSupplier');
    Route::post('/devoluciones/cliente', [ReturnController::class, 'storeCustomer'])
        ->middleware('team4.permission:devoluciones.storeCustomer')
        ->name('equipo4.devoluciones.storeCustomer');

    // ---------- Conteos ----------
    Route::get('/conteos', [StockCountController::class, 'index'])
        ->middleware('team4.permission:conteos.view')
        ->name('equipo4.conteos.index');
    Route::post('/conteos', [StockCountController::class, 'store'])
        ->middleware('team4.permission:conteos.create')
        ->name('equipo4.conteos.store');
    Route::get('/conteos/{id}/details', [StockCountController::class, 'show'])
        ->middleware('team4.permission:conteos.view')
        ->name('equipo4.conteos.show');
    Route::post('/conteos/{id}/capture', [StockCountController::class, 'capture'])
        ->middleware('team4.permission:conteos.capture')
        ->name('equipo4.conteos.capture');
    Route::post('/conteos/{id}/close', [StockCountController::class, 'close'])
        ->middleware('team4.permission:conteos.close')
        ->name('equipo4.conteos.close');
    Route::delete('/conteos/{id}', [StockCountController::class, 'destroy'])
        ->middleware('team4.permission:conteos.delete')
        ->name('equipo4.conteos.destroy');
});

// API publica (solo lectura, sin permisos de usuario — Eq.3 consume esto con HMAC aparte)
Route::prefix('api/equipo4')->group(function () {
    Route::get('/products', [Team4Controller::class, 'products'])->middleware('throttle:60,1');
    Route::get('/warehouses', [Team4Controller::class, 'warehouses'])->middleware('throttle:60,1');
    Route::get('/locations', [Team4Controller::class, 'locations'])->middleware('throttle:60,1');
    Route::get('/inventory', [Team4Controller::class, 'inventory'])->middleware('throttle:60,1');
    Route::get('/movements', [Team4Controller::class, 'movements'])->middleware('throttle:60,1');
    Route::get('/suppliers', [Team4Controller::class, 'suppliers'])->middleware('throttle:60,1');
    Route::get('/purchase-orders', [Team4Controller::class, 'purchaseOrders'])->middleware('throttle:60,1');
});

// ============================================================
// API Equipo 7 - Recompensas fisicas (adapter sobre motor E3)
// ============================================================
Route::prefix('api/v1/inventory')->middleware([
    \App\Http\Middleware\Team4ServiceSignature::class,
    'throttle:120,1',
])->group(function () {
    Route::match(['GET','POST'], '/availability',        [\App\Api\Team7ApiController::class, 'availability']);
    Route::post(           '/reservations',              [\App\Api\Team7ApiController::class, 'reserve']);
    Route::get(            '/reservations/{id}',         [\App\Api\Team7ApiController::class, 'show']);
    Route::post(           '/reservations/{id}/confirm', [\App\Api\Team7ApiController::class, 'confirm']);
    Route::post(           '/reservations/{id}/release', [\App\Api\Team7ApiController::class, 'release']);
});

// Alias para compatibilidad con testers
Route::middleware(['team4.signature'])->prefix('api/equipo4/inventario')->group(function () {
    Route::get('/availability', [\App\Api\Team7ApiController::class, 'availability']);
    Route::get('/reservations/{id}', [\App\Api\Team7ApiController::class, 'show']);
});

