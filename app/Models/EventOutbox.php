<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Casts\AsBsonDocument;
use MongoDB\Laravel\Eloquent\Model;

class EventOutbox extends Model
{
    /**
     * Transporte durable de eventos de dominio para integración.
     * No constituye una bitácora de auditoría inmutable.
     */
    protected $connection = 'mongodb';

    // Preserve the collection already used by Eloquent's pluralized model name.
    protected $table = 'event_outboxes';

    protected $fillable = ['event_id', 'event_name', 'aggregate_id', 'payload', 'occurred_at', 'published_at', 'attempts', 'last_error', 'claim_token', 'claim_expires_at', 'next_attempt_at'];

    protected $casts = [
        'payload' => AsBsonDocument::class,
        'occurred_at' => 'datetime',
        'published_at' => 'datetime',
        'claim_expires_at' => 'datetime',
        'next_attempt_at' => 'datetime',
    ];
}
