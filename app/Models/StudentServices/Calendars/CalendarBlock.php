<?php

namespace App\Models\StudentServices\Calendars;

use Carbon\CarbonInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

/**
 * Modulo 5.10 - Franja en la que un recurso no puede reservarse
 * (mantenimiento, evento institucional, limpieza...).
 *
 * @property-read string $id
 * @property string $folio
 * @property string $resource_type
 * @property ObjectId|string $resource_id
 * @property CarbonInterface $start_at
 * @property CarbonInterface $end_at
 * @property string $reason
 * @property string|null $created_by
 * @property int $cancelled_bookings
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class CalendarBlock extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'calendar_blocks';

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
