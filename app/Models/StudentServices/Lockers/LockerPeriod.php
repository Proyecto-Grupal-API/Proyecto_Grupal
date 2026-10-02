<?php

namespace App\Models\StudentServices\Lockers;

use MongoDB\Laravel\Eloquent\Model;

class LockerPeriod extends Model
{
    public const STATUSES = [
        'active',
        'closed',
    ];

    protected $connection = 'mongodb';

    protected $collection = 'locker_periods';

    protected $fillable = [
        'code',
        'name',
        'starts_at',
        'ends_at',
        'prices',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function priceFor(
        string $size
    ): ?string {
        $value = data_get(
            $this->prices,
            $size
        );

        return $value === null
            ? null
            : (string) $value;
    }

    public function toPayload(): array
    {
        return [
            'id' => (string) $this->id,
            'code' => $this->code,
            'name' => $this->name,

            'starts_at' =>
                $this->starts_at?->format(
                    'Y-m-d'
                ),

            'ends_at' =>
                $this->ends_at?->format(
                    'Y-m-d'
                ),

            'prices' => [
                'small' =>
                    $this->priceFor(
                        'small'
                    ),

                'medium' =>
                    $this->priceFor(
                        'medium'
                    ),

                'large' =>
                    $this->priceFor(
                        'large'
                    ),
            ],

            'status' => $this->status,
        ];
    }
}
