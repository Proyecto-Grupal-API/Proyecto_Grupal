<?php

namespace App\Models\Marketplace;

use MongoDB\Laravel\Eloquent\Model;

class Product extends Model
{
    protected $connection = 'marketplace';

    protected $table = 'marketplace_products';

    protected $fillable = ['inventory_product_id', 'inventory_location_id',
        'business_id', 'name', 'slug', 'kind', 'category', 'description', 'image',
        'price', 'currency', 'active', 'available', 'variants', 'attributes',
        'inventory_required', 'stock_snapshot', 'tags',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'active' => 'boolean',
        'available' => 'boolean',
        'inventory_required' => 'boolean',
        'variants' => 'array',
        'attributes' => 'array',
        'stock_snapshot' => 'integer',
        'tags' => 'array',
    ];
}
