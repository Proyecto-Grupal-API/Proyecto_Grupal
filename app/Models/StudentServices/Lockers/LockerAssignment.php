<?php

namespace App\Models\StudentServices\Lockers;

use Carbon\CarbonInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

/**
 * @property-read string $id
 * @property string $folio
 * @property ObjectId|string $locker_id
 * @property ObjectId|string $period_id
 * @property string $student_id
 * @property ObjectId|string|null $request_id
 * @property string $source
 * @property string $status
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $ends_at
 * @property CarbonInterface|null $released_at
 * @property string|null $release_reason
 * @property int $renewal_count
 * @property ObjectId|string|null $renewed_from_id
 * @property string|null $assigned_by
 * @property string|null $notes
 * @property string|null $payment_reference
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
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

    protected $table = 'locker_assignments';

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
        mixed $value
    ): void {
        $this->attributes['locker_id'] =
            $this->toObjectId($value);
    }

    public function setPeriodIdAttribute(
        mixed $value
    ): void {
        $this->attributes['period_id'] =
            $this->toObjectId($value);
    }

    public function setRequestIdAttribute(
        mixed $value
    ): void {
        $this->attributes['request_id'] =
            $this->toObjectId($value);
    }

    public function setRenewedFromIdAttribute(
        mixed $value
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
