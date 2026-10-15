<?php

namespace App\Models\Marketplace;

use MongoDB\Laravel\Eloquent\Model;

class Storefront extends Model
{
    protected $connection = 'marketplace';

    protected $table = 'marketplace_storefronts';

    protected $fillable = [
        'business_id', 'name', 'brand', 'description', 'banner', 'logo', 'policies',
        'hours', 'delivery_points', 'channels', 'published',
    ];

    protected $casts = [
        'policies' => 'array', 'hours' => 'array', 'delivery_points' => 'array',
        'channels' => 'array', 'published' => 'boolean',
    ];
}
