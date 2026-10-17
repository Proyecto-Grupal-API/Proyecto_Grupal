<?php

namespace App\Models\InventoryModule;

class CostHistory extends Team4Document
{
    protected $table = 'cost_histories';

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
