<?php

namespace App\Models\InventoryModule;

class Product extends Team4Document
{
    protected $collection = 'products';

    protected $fillable = [
        'business_id',
        'sku',
        'name',
        'description',
        'category',
        'sizes',
        'colors',
        'seasons',
        'collections',
        'packages',
        'preorder',
        'official',
        'stock_min',
        'stock_max',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'official' => 'boolean',
        'stock_min' => 'integer',
        'stock_max' => 'integer',
    ];
}
