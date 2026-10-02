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
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
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
