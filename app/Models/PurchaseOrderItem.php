<?php
namespace App\Models;

class PurchaseOrderItem extends Team4Document
{
    protected $collection = 'purchase_order_items';
    protected $fillable = [
        'business_id',
        'purchase_order_id',
        'line',
        'product_id',
        'product_sku',
        'product_name',
        'quantity',
        'unit_cost',
        'subtotal',
    ];
    protected $casts = [
        'line' => 'integer',
        'quantity' => 'integer',
        'unit_cost' => 'float',
        'subtotal' => 'float',
    ];
}
