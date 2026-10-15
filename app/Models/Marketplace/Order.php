<?php

namespace App\Models\Marketplace;

use MongoDB\Laravel\Eloquent\Model;

class Order extends Model
{
    protected $connection = 'marketplace';

    protected $table = 'marketplace_orders';

    protected $fillable = ['request_hash', 'cart_id', 'cart_version', 'total_cents', 'reservation_id', 'inventory_state', 'reserve_payload', 'hold_expires_at', 'cancellation_reason',
        'folio', 'buyer_id', 'business_id', 'items', 'subtotal', 'discount', 'total',
        'currency', 'payment_method', 'payment_status', 'status', 'delivery_point',
        'notes', 'idempotency_key', 'history', 'created_at', 'updated_at',
    ];

    protected $casts = [
        'items' => 'array', 'reserve_payload' => 'array', 'hold_expires_at' => 'datetime', 'total_cents' => 'integer', 'cart_version' => 'integer',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'history' => 'array',
    ];
}
