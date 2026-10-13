<?php

namespace App\Models\StudentServices\Services;

use Carbon\CarbonInterface;
use MongoDB\Laravel\Eloquent\Model;

/**
 * @property-read string $id
 * @property string $folio
 * @property string $student_id
 * @property string $service_type
 * @property int $quantity
 * @property string|null $observations
 * @property int|null $quoted_amount_cents
 * @property string $payment_status
 * @property string|null $payment_reference_id
 * @property string $status
 * @property CarbonInterface|null $requested_at
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface|null $processing_at
 * @property CarbonInterface|null $ready_at
 * @property CarbonInterface|null $delivered_at
 * @property CarbonInterface|null $cancelled_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ServiceOrder extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'service_orders';

    protected $fillable = [
        'folio',
        'student_id',
        'service_type',
        'quantity',
        'observations',

        'quoted_amount_cents',

        'payment_status',
        'payment_reference_id',

        'status',

        'requested_at',
        'paid_at',
        'processing_at',
        'ready_at',
        'delivered_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',

            'quoted_amount_cents' => 'integer',

            'requested_at' => 'datetime',

            'paid_at' => 'datetime',

            'processing_at' => 'datetime',

            'ready_at' => 'datetime',

            'delivered_at' => 'datetime',

            'cancelled_at' => 'datetime',
        ];
    }
}
