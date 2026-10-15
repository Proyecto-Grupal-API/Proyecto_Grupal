<?php

namespace App\Models\Marketplace;

use MongoDB\Laravel\Eloquent\Model;

class PaymentIntent extends Model
{
    protected $connection = 'marketplace';

    protected $table = 'marketplace_payment_intents';

    protected $fillable = [
        'order_id', 'business_id', 'buyer_id', 'method', 'amount', 'currency',
        'status', 'reference', 'provider', 'evidence', 'metadata', 'idempotency_key',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'evidence' => 'array',
        'metadata' => 'array',
    ];
}
