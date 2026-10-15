<?php

namespace App\Models\Marketplace;

use MongoDB\Laravel\Eloquent\Model;

class Business extends Model
{
    protected $connection = 'marketplace';

    protected $table = 'marketplace_businesses';

    protected $fillable = ['_id', 'organization_id', 'inventory_ready',
        'name', 'slug', 'type', 'owner_id', 'description', 'status', 'visibility',
        'verified', 'logo', 'banner', 'policies', 'hours', 'delivery_points',
        'contact', 'payment_methods', 'tags', 'spei_instructions', 'openpay_profile_id',
    ];

    protected $casts = [
        'verified' => 'boolean', 'inventory_ready' => 'boolean',
        'payment_methods' => 'array',
        'tags' => 'array',
        'hours' => 'array',
        'delivery_points' => 'array',
        'contact' => 'array',
    ];
}
