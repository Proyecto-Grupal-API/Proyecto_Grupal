<?php

namespace App\Models\Marketplace;

use MongoDB\Laravel\Eloquent\Model;

class ReturnRequest extends Model
{
    protected $connection = 'marketplace';

    protected $table = 'marketplace_return_requests';

    protected $fillable = [
        'order_id', 'buyer_id', 'business_id', 'reason', 'evidence', 'status',
        'requested_at', 'resolved_at', 'resolution_notes', 'resolved_by',
    ];

    protected $casts = ['evidence' => 'array', 'requested_at' => 'datetime', 'resolved_at' => 'datetime'];
}
