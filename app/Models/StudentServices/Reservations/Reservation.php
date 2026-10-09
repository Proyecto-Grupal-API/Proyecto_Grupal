<?php

namespace App\Models\StudentServices\Reservations;

use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

class Reservation extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'reservations';

    protected $fillable = [
        'folio',
        'facility_id',
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

    public function setFacilityIdAttribute($value): void
    {
        $this->attributes['facility_id'] = $value instanceof ObjectId
            ? $value
            : new ObjectId((string) $value);
    }
}
