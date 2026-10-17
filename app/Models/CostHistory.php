<?php

namespace App\Models;

class CostHistory extends Team4Document
{
    protected $collection = 'cost_histories';

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
