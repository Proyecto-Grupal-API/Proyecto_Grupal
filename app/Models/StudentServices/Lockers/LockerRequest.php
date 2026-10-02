<?php

namespace App\Models\StudentServices\Lockers;

use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

class LockerRequest extends Model
{
    public const TYPES = [
        'paid',
        'council',
        'scholarship',
    ];

    public const STATUSES = [
        'pending',
        'paid',
        'assigned',
        'rejected',
        'cancelled',
    ];

    protected $connection = 'mongodb';

    protected $collection =
        'locker_requests';

    protected $fillable = [
        'folio',
        'student_id',
        'period_id',
        'locker_id',
        'preferred_size',
        'preferred_building',
        'request_type',
        'status',
        'amount',
        'payment_reference',
        'paid_at',
        'reviewed_by',
        'reviewed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function setPeriodIdAttribute(
        $value
    ): void {
        $this->attributes['period_id'] =
            $this->toObjectId($value);
    }

    public function setLockerIdAttribute(
        $value
    ): void {
        $this->attributes['locker_id'] =
            $this->toObjectId($value);
    }

    private function toObjectId(
        mixed $value
    ): ?ObjectId {
        if ($value === null) {
            return null;
        }

        return $value instanceof ObjectId
            ? $value
            : new ObjectId(
                (string) $value
            );
    }
}
