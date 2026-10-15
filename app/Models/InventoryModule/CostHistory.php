<?php

namespace App\Models\InventoryModule;

class CostHistory extends Team4Document
{
    protected $collection = 'cost_history';

    protected $fillable = [
        'business_id',
        'product_id',
        'cost',
        'source',
        'effective_at',
    ];

    protected $casts = [
        'cost' => 'float',
        'effective_at' => 'datetime',
    ];
}
