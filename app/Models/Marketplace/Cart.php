<?php

namespace App\Models\Marketplace;

use MongoDB\Laravel\Eloquent\Model;

class Cart extends Model
{
    protected $connection = 'marketplace';

    protected $table = 'marketplace_carts';

    protected $fillable = ['version', 'user_id', 'items', 'subtotal', 'updated_at'];

    protected $casts = [
        'items' => 'array', 'version' => 'integer',
        'subtotal' => 'decimal:2',
    ];
}
