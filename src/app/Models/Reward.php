<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Reward extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'rewards';

    protected $fillable = [
        'business_id',
        'name',
        'description',
        'points_required',
        'stock',
        'reserved_stock',
        'linked_product_reference',
        'is_physical',
        'valid_from',
        'valid_to',
        'limit_per_user',
        'limit_period_days',
        'status',
        'images',
    ];

    protected $casts = [
        'points_required' => 'integer',
        'stock' => 'integer',
        'reserved_stock' => 'integer',
        'is_physical' => 'boolean',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'limit_per_user' => 'integer',
        'limit_period_days' => 'integer',
        'images' => 'array',
    ];

    public function items()
    {
        return $this->hasMany(RewardItem::class, 'reward_id');
    }

    public function availableStock(): int
    {
        return max(0, $this->stock - $this->reserved_stock);
    }
}
