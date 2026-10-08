<?php

namespace App\Models\StudentServices\Calendars;

use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

/**
 * Modulo 5.10 - Franja en la que un recurso no puede reservarse
 * (mantenimiento, evento institucional, limpieza...).
 */
class CalendarBlock extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'calendar_blocks';

    protected $fillable = [
        'folio',
        'resource_type',
        'resource_id',
        'start_at',
        'end_at',
        'reason',
        'created_by',
        'cancelled_bookings',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'cancelled_bookings' => 'integer',
        ];
    }

    public function setResourceIdAttribute(mixed $value): void
    {
        $this->attributes['resource_id'] = $value instanceof ObjectId
            ? $value
            : new ObjectId((string) $value);
    }
}
