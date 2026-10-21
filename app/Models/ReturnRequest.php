<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class ReturnRequest extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'return_requests';

    protected $fillable = [
        'order_id','buyer_id','business_id','reason','evidence','status',
        'requested_at','resolved_at','resolution_notes','resolved_by',
        'refund_id','refund_status','refund_result',
    ];

    protected $casts = [
        'evidence' => 'array',
        'requested_at' => 'datetime',
        'resolved_at' => 'datetime',
        'refund_result' => 'array',
    ];
}
