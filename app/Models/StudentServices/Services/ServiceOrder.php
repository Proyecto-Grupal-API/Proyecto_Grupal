<?php

namespace App\Models\StudentServices\Services;

use MongoDB\Laravel\Eloquent\Model;

class ServiceOrder extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'service_orders';

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

            'quoted_amount_cents' =>
                'integer',

            'requested_at' =>
                'datetime',

            'paid_at' =>
                'datetime',

            'processing_at' =>
                'datetime',

            'ready_at' =>
                'datetime',

            'delivered_at' =>
                'datetime',

            'cancelled_at' =>
                'datetime',
        ];
    }
}
