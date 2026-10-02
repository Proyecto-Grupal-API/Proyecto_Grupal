<?php

namespace App\Models\StudentServices\Lockers;

use MongoDB\Laravel\Eloquent\Model;

class Locker extends Model
{
    public const SIZES = [
        'small',
        'medium',
        'large',
    ];

    public const STATUSES = [
        'available',
        'reserved',
        'occupied',
        'maintenance',
    ];

    protected $connection = 'mongodb';

    protected $collection = 'lockers';

    protected $fillable = [
        'code',
        'qr_code',
        'building',
        'zone',
        'size',
        'status',
        'notes',
    ];

    public function toPayload(): array
    {
        return [
            'id' => (string) $this->id,
            'code' => $this->code,
            'qr_code' => $this->qr_code,
            'building' => $this->building,
            'zone' => $this->zone,
            'size' => $this->size,
            'status' => $this->status,
            'notes' => $this->notes,
        ];
    }
}
