<?php

use App\Http\Controllers\MarketplaceController;
use Illuminate\Support\Facades\Route;

Route::post('/logout', [\App\Http\Controllers\AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect()->route('marketplace.index'));
    Route::get('/tienda', [MarketplaceController::class, 'index'])->name('marketplace.index');
    Route::get('/tienda/negocios', [MarketplaceController::class, 'businesses'])->name('marketplace.businesses');
    Route::get('/tienda/productos', [MarketplaceController::class, 'products'])->name('marketplace.products');
    Route::post('/tienda/carrito', [MarketplaceController::class, 'cart'])->name('marketplace.cart');
    Route::post('/tienda/checkout', [MarketplaceController::class, 'checkout'])->name('marketplace.checkout');
    Route::get('/tienda/pedidos', [MarketplaceController::class, 'orders'])->name('marketplace.orders');

    Route::get('/api/tienda/catalogo', [MarketplaceController::class, 'catalog'])->name('api.marketplace.catalog');
    Route::get('/api/tienda/negocios', [MarketplaceController::class, 'businessesApi'])->name('api.marketplace.businesses');
});
