<?php
namespace App\Models;

class GoodsReceiptItem extends Team4Document
{
    protected $collection = 'goods_receipt_items';
    protected $fillable = [
        'business_id',
        'goods_receipt_id',
        'purchase_order_item_id',
        'product_id',
        'product_sku',
        'quantity',
        'unit_cost',
    ];
    protected $casts = [
        'quantity' => 'integer',
        'unit_cost' => 'float',
    ];
}
