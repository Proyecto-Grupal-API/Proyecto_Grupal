<?php

namespace App\Models\StudentServices\Lockers;

use Carbon\CarbonInterface;
use MongoDB\Laravel\Eloquent\Model;

/**
 * @property-read string $id
 * @property string $code
 * @property string $qr_code
 * @property string $building
 * @property string $zone
 * @property string $size
 * @property string $status
 * @property string|null $notes
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
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

    protected $table = 'lockers';

    protected $fillable = [
        'code',
        'qr_code',
        'building',
        'zone',
        'size',
        'status',
        'notes',
    ];

    /**
     * @return array{
     *     id: string,
     *     code: string,
     *     qr_code: string,
     *     building: string,
     *     zone: string,
     *     size: string,
     *     status: string,
     *     notes: string|null
     * }
     */
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
