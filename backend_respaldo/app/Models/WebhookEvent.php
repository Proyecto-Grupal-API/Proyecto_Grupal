<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class WebhookEvent extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'webhook_events';

    public $timestamps = true;

    protected $fillable = [
        'source',
        'event_id',
        'event_type',
        'payload',
        'status',
        'retry_count',
        'next_retry_at',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'retry_count' => 'integer',
        'next_retry_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
