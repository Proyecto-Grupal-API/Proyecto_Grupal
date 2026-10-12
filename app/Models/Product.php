<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Product extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'products';

    protected $fillable = [
        'business_id','name','slug','kind','category','description','image',
        'price','currency','active','available','variants','attributes',
        'inventory_required','stock_snapshot','tags'
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
