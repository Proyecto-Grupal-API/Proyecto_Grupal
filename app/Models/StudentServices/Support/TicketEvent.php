<?php

namespace App\Models\StudentServices\Support;

use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

class TicketEvent extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'ticket_events';

    protected $fillable = [
        'ticket_id',
        'event_type',
        'from_status',
        'to_status',
        'actor_id',
        'message',
        'created_at',
    ];

    public function setTicketIdAttribute(
        $value
    ): void {
        $this->attributes['ticket_id'] =
            $value instanceof ObjectId
                ? $value
                : new ObjectId(
                (string) $value
            );
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
