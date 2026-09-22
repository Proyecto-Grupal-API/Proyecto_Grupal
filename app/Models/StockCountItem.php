<?php
namespace App\Models;

class StockCountItem extends Team4Document
{
    protected $collection = 'stock_count_items';
    protected $fillable = [
        'business_id',
        'stock_count_id',
        'product_id',
        'product_sku',
        'product_name',
        'expected_qty',
        'counted_qty',
        'difference',
        'unit_cost',
        'notes',
    ];
    protected $casts = [
        'expected_qty' => 'integer',
        'counted_qty' => 'integer',
        'difference' => 'integer',
        'unit_cost' => 'float',
    ];
}
