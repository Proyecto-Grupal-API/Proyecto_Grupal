<?php

namespace App\Models\StudentServices\Rentals;

use Carbon\CarbonInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

/**
 * @property-read string $id
 * @property ObjectId|string $asset_id
 * @property string|null $inventory_item_id
 * @property string $student_id
 * @property CarbonInterface|null $requested_at
 * @property CarbonInterface|null $due_at
 * @property CarbonInterface|null $returned_at
 * @property string $status
 * @property string|null $notes
 * @property int|null $deposit_cents
 * @property string|null $deposit_status
 * @property string|null $deposit_hold_reference
 * @property int|null $damage_charge_cents
 * @property CarbonInterface|null $deposit_settled_at
 * @property CarbonInterface|null $picked_up_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Rental extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'rentals';

    protected $fillable = [
        'asset_id',
        'inventory_item_id',
        'student_id',
        'requested_at',
        'due_at',
        'returned_at',
        'status',
        'notes',
        'deposit_cents',
        'deposit_status',
        'deposit_hold_reference',
        'damage_charge_cents',
        'deposit_settled_at',
        'picked_up_at',
    ];

    /**
     * Estados del depósito en garantía (5.7).
     */
    public const DEPOSIT_STATUSES = [
        'none',
        'held',
        'released',
        'charged',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'due_at' => 'datetime',
            'returned_at' => 'datetime',
            'deposit_settled_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'deposit_cents' => 'integer',
            'damage_charge_cents' => 'integer',
        ];
    }

    public function setAssetIdAttribute(
        mixed $value
    ): void {
        $this->attributes['asset_id'] =
            $value instanceof ObjectId
                ? $value
                : new ObjectId(
                    (string) $value
                );
    }
}
