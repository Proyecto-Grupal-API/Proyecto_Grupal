<?php

namespace App\Models\StudentServices\RestSpaces;

use Carbon\CarbonInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

/**
 * Modulo 5.6 - Reservación de una zona de descanso. Usa los mismos
 * estados y ciclo de vida que el motor de calendarios (5.10).
 *
 * @property-read string $id
 * @property string $folio
 * @property ObjectId|string $rest_space_id
 * @property string $student_id
 * @property CarbonInterface $start_at
 * @property CarbonInterface $end_at
 * @property string $status
 * @property string|null $idempotency_key
 * @property CarbonInterface|null $waitlisted_at
 * @property CarbonInterface|null $promoted_at
 * @property CarbonInterface|null $checked_in_at
 * @property CarbonInterface|null $checked_out_at
 * @property CarbonInterface|null $no_show_at
 * @property CarbonInterface|null $expired_at
 * @property CarbonInterface|null $cancelled_at
 * @property string|null $cancelled_by
 * @property string|null $cancellation_reason
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class RestBooking extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'rest_bookings';

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
