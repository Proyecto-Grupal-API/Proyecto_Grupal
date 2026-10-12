<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Cart extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'carts';

    protected $fillable = ['user_id','items','subtotal','updated_at'];

    protected $casts = [
        'items' => 'array',
        'subtotal' => 'decimal:2',
    ];
}
