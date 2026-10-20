<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class RewardItem extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'reward_items';

    protected $fillable = [
        'reward_id',
        'variant_name',
        'sku_reference',
        'stock',
        'reserved_stock',
    ];

    protected $casts = [
        'stock' => 'integer',
        'reserved_stock' => 'integer',
    ];

    public function reward()
    {
        return $this->belongsTo(Reward::class, 'reward_id');
    }
}
