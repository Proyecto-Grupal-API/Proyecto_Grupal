<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class AnalyticsEvent extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'analytics_events';

    public $timestamps = true;

    protected $fillable = [
        'source_domain',
        'event_type',
        'entity_type',
        'entity_id',
        'payload',
        'idempotency_key',
        'processed',
        'processed_at',
        'processing_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed' => 'boolean',
        'processed_at' => 'datetime',
    ];
}
