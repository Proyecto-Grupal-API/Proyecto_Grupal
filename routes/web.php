<?php

use App\Http\Controllers\BusinessManagementController;
use App\Http\Controllers\MarketplaceController;
use Illuminate\Support\Facades\Route;

Route::post('/logout', [\App\Http\Controllers\AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect()->route('marketplace.index'));
    Route::get('/tienda', [MarketplaceController::class, 'index'])->name('marketplace.index');
    Route::get('/tienda/negocios', [MarketplaceController::class, 'businesses'])->name('marketplace.businesses');
    Route::post('/tienda/negocios', [BusinessManagementController::class, 'storeBusiness'])->name('marketplace.businesses.store');
    Route::put('/tienda/negocios/{businessId}', [BusinessManagementController::class, 'updateBusiness'])->name('marketplace.businesses.update');
    Route::post('/tienda/negocios/{businessId}/estado', [BusinessManagementController::class, 'updateBusinessStatus'])->name('marketplace.businesses.status');
    Route::get('/tienda/solicitudes', [BusinessManagementController::class, 'applications'])->name('marketplace.applications');
    Route::post('/tienda/solicitudes', [BusinessManagementController::class, 'submitApplication'])->name('marketplace.applications.store');
    Route::post('/tienda/solicitudes/{applicationId}/resolver', [BusinessManagementController::class, 'reviewApplication'])->name('marketplace.applications.review');
    Route::post('/tienda/negocios/{businessId}/integrantes', [BusinessManagementController::class, 'saveMember'])->name('marketplace.members.store');
    Route::put('/tienda/negocios/{businessId}/tienda', [BusinessManagementController::class, 'updateStorefront'])->name('marketplace.storefront.update');

    Route::get('/tienda/productos', [MarketplaceController::class, 'products'])->name('marketplace.products');
    Route::post('/tienda/productos', [BusinessManagementController::class, 'storeProduct'])->name('marketplace.products.store');
    Route::put('/tienda/productos/{productId}', [BusinessManagementController::class, 'updateProduct'])->name('marketplace.products.update');
    Route::delete('/tienda/productos/{productId}', [BusinessManagementController::class, 'deactivateProduct'])->name('marketplace.products.deactivate');

    Route::post('/tienda/carrito', [MarketplaceController::class, 'cart'])->name('marketplace.cart');
    Route::put('/tienda/carrito', [MarketplaceController::class, 'updateCart'])->name('marketplace.cart.update');
    Route::delete('/tienda/carrito/{productId}', [MarketplaceController::class, 'removeCartItem'])->name('marketplace.cart.remove');
    Route::delete('/tienda/carrito', [MarketplaceController::class, 'clearCart'])->name('marketplace.cart.clear');
    Route::post('/tienda/checkout', [MarketplaceController::class, 'checkout'])->name('marketplace.checkout');
    Route::get('/tienda/pedidos', [MarketplaceController::class, 'orders'])->name('marketplace.orders');
    Route::get('/tienda/vendedor', [BusinessManagementController::class, 'sellerDashboard'])->name('marketplace.seller.dashboard');
    Route::get('/tienda/vendedor/pedidos', [BusinessManagementController::class, 'sellerOrders'])->name('marketplace.seller.orders');
    Route::post('/tienda/pedidos/{orderId}/estado', [BusinessManagementController::class, 'transitionOrder'])->name('marketplace.orders.transition');
    Route::post('/tienda/pedidos/{orderId}/postventa', [BusinessManagementController::class, 'requestReturn'])->name('marketplace.orders.return');
    Route::post('/tienda/pedidos/{orderId}/comprobante', [BusinessManagementController::class, 'submitDirectPaymentEvidence'])->name('marketplace.orders.evidence');
    Route::post('/tienda/pedidos/{orderId}/resolver-pago', [BusinessManagementController::class, 'resolveDirectPayment'])->name('marketplace.orders.resolve-payment');
    Route::get('/tienda/postventa', [MarketplaceController::class, 'returns'])->name('marketplace.returns');
    Route::post('/tienda/postventa/{returnId}/resolver', [BusinessManagementController::class, 'resolveReturn'])->name('marketplace.returns.resolve');
    Route::get('/tienda/auditoria', [BusinessManagementController::class, 'auditLog'])->name('marketplace.audit');

    Route::get('/api/tienda/catalogo', [MarketplaceController::class, 'catalog'])->name('api.marketplace.catalog');
    Route::get('/api/tienda/negocios', [MarketplaceController::class, 'businessesApi'])->name('api.marketplace.businesses');
});
