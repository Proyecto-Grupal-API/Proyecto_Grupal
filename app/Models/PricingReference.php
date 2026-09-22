<?php

namespace App\Models;

class PricingReference extends Team4Document
{
    protected $collection = 'pricing_references';

    protected $fillable = [
        'business_id',
        'product_id',
        'average_cost',
        'last_cost',
        'suggested_margin',
    ];

    protected $casts = [
        'average_cost' => 'float',
        'last_cost' => 'float',
        'suggested_margin' => 'float',
    ];
}
