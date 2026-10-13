<?php

namespace App\Models\StudentServices\Support;

use Carbon\CarbonInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

/**
 * @property-read string $id
 * @property ObjectId|string $ticket_id
 * @property string $event_type
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $actor_id
 * @property string|null $message
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class TicketEvent extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'ticket_events';

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
        mixed $value
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
