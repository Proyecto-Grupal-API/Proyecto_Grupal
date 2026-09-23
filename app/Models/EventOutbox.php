<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Eloquent\Casts\AsBsonDocument;

class EventOutbox extends Model
{
    /**
     * Transporte durable de eventos de dominio para integración.
     * No constituye una bitácora de auditoría inmutable.
     */
    protected $connection = 'mongodb';
    protected $collection = 'event_outbox';
    protected $fillable = ['event_id', 'event_name', 'aggregate_id', 'payload', 'occurred_at', 'published_at', 'attempts', 'last_error'];
    protected $casts = ['payload' => AsBsonDocument::class, 'occurred_at' => 'datetime', 'published_at' => 'datetime'];
}
