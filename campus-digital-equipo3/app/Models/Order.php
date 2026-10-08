<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Order extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'orders';

    protected $fillable = [
        'folio','buyer_id','business_id','items','subtotal','discount','total',
        'currency','payment_method','payment_status','status','delivery_point',
        'notes','idempotency_key','created_at','updated_at'
    ];

    protected $casts = [
        'items' => 'array',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
    ];
}
