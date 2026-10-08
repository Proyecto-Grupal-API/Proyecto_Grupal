<?php

namespace App\Models\StudentServices\RestSpaces;

use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

/**
 * Modulo 5.6 - Reservación de una zona de descanso. Usa los mismos
 * estados y ciclo de vida que el motor de calendarios (5.10).
 */
class RestBooking extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'rest_bookings';

    protected $fillable = [
        'folio',
        'rest_space_id',
        'student_id',
        'start_at',
        'end_at',
        'status',
        'idempotency_key',
        'waitlisted_at',
        'promoted_at',
        'checked_in_at',
        'checked_out_at',
        'no_show_at',
        'expired_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'waitlisted_at' => 'datetime',
            'promoted_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'no_show_at' => 'datetime',
            'expired_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function setRestSpaceIdAttribute(mixed $value): void
    {
        $this->attributes['rest_space_id'] = $value instanceof ObjectId
            ? $value
            : new ObjectId((string) $value);
    }
}
