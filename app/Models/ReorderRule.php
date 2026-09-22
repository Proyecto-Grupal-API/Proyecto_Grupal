<?php

namespace App\Models;

class ReorderRule extends Team4Document
{
    protected $collection = 'reorder_rules';

    protected $fillable = [
        'business_id',
        'product_id',
        'location_id',
        'min_qty',
        'max_qty',
        'reorder_point',
        'active',
    ];

    protected $casts = [
        'min_qty' => 'integer',
        'max_qty' => 'integer',
        'reorder_point' => 'integer',
        'active' => 'boolean',
    ];
}
