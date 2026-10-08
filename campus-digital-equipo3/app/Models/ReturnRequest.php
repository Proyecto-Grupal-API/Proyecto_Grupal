<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class ReturnRequest extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'return_requests';

    protected $fillable = [
        'order_id','buyer_id','business_id','reason','evidence','status',
        'requested_at','resolved_at','resolution_notes'
    ];

    protected $casts = ['evidence' => 'array'];
}
