<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\CostHistory;
use App\Models\CustomerReturn;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Inventory;
use App\Models\Location;
use App\Models\PricingReference;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\ReorderRule;
use App\Models\StockAlert;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\Warehouse;
use App\Observers\MongoAuditObserver;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if (config('app.url')) {
            URL::forceRootUrl(config('app.url'));
        }

        foreach ([
            Category::class,
            CostHistory::class,
            CustomerReturn::class,
            GoodsReceipt::class,
            GoodsReceiptItem::class,
            Inventory::class,
            Location::class,
            PricingReference::class,
            Product::class,
            PurchaseOrder::class,
            PurchaseOrderItem::class,
            ReorderRule::class,
            StockAlert::class,
            StockCount::class,
            StockCountItem::class,
            StockMovement::class,
            StockReservation::class,
            Supplier::class,
            SupplierReturn::class,
            Warehouse::class,
        ] as $model) {
            $model::observe(MongoAuditObserver::class);
        }
    }
}