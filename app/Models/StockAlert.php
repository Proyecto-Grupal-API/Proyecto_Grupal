<?php

namespace App\Models;

class StockAlert extends Team4Document
{
    protected $collection = 'stock_alerts';

    protected $fillable = [
        'business_id',
        'product_id',
        'location_id',
        'type',
        'current_qty',
        'threshold',
        'priority',
        'status',
        'message',
    ];

    protected $casts = [
        'current_qty' => 'integer',
        'threshold' => 'integer',
    ];
}
