<?php

namespace App\Models\StudentServices\Lockers;

use App\Services\StudentServices\Payments\Money;
use Carbon\CarbonInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

/**
 * @property-read string $id
 * @property string $folio
 * @property string $student_id
 * @property ObjectId|string $period_id
 * @property ObjectId|string|null $locker_id
 * @property string|null $preferred_size
 * @property string|null $preferred_building
 * @property string $request_type
 * @property string $status
 * @property int|null $amount_cents
 * @property string|null $payment_reference
 * @property CarbonInterface|null $paid_at
 * @property string|null $reviewed_by
 * @property CarbonInterface|null $reviewed_at
 * @property string|null $notes
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
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

    protected $table = 'locker_requests';

    protected $fillable = [
        'folio',
        'student_id',
        'period_id',
        'locker_id',
        'preferred_size',
        'preferred_building',
        'request_type',
        'status',
        'amount_cents',
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
            'amount_cents' => 'integer',
        ];
    }

    /**
     * Importe en centavos; las solicitudes anteriores al cambio guardaban
     * `amount` en pesos (Decimal128) y se leen como respaldo.
     */
    public function amountInCents(): ?int
    {
        if ($this->amount_cents !== null) {
            return (int) $this->amount_cents;
        }

        $legacy = $this->getAttribute('amount');

        return $legacy === null
            ? null
            : Money::toCents($legacy);
    }

    public function setPeriodIdAttribute(
        mixed $value
    ): void {
        $this->attributes['period_id'] =
            $this->toObjectId($value);
    }

    public function setLockerIdAttribute(
        mixed $value
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
