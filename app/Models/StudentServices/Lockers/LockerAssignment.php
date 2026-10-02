<?php

namespace App\Models\StudentServices\Lockers;

use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

class LockerAssignment extends Model
{
    public const SOURCES = [
        'paid',
        'council',
        'scholarship',
    ];

    public const STATUSES = [
        'active',
        'released',
        'expired',
    ];

    protected $connection = 'mongodb';

    protected $collection =
        'locker_assignments';

    protected $fillable = [
        'folio',
        'locker_id',
        'period_id',
        'student_id',
        'request_id',
        'source',
        'status',
        'starts_at',
        'ends_at',
        'released_at',
        'release_reason',
        'renewal_count',
        'renewed_from_id',
        'assigned_by',
        'notes',
        'payment_reference',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'released_at' => 'datetime',
            'renewal_count' => 'integer',
        ];
    }

    public function setLockerIdAttribute(
        $value
    ): void {
        $this->attributes['locker_id'] =
            $this->toObjectId($value);
    }

    public function setPeriodIdAttribute(
        $value
    ): void {
        $this->attributes['period_id'] =
            $this->toObjectId($value);
    }

    public function setRequestIdAttribute(
        $value
    ): void {
        $this->attributes['request_id'] =
            $this->toObjectId($value);
    }

    public function setRenewedFromIdAttribute(
        $value
    ): void {
        $this->attributes[
        'renewed_from_id'
        ] = $this->toObjectId(
            $value
        );
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
