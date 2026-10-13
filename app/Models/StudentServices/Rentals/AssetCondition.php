<?php

namespace App\Models\StudentServices\Rentals;

use Carbon\CarbonInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

/**
 * @property-read string $id
 * @property ObjectId|string $rental_id
 * @property ObjectId|string $asset_id
 * @property string|null $inventory_item_id
 * @property string $type
 * @property string $condition
 * @property string|null $notes
 * @property CarbonInterface|null $recorded_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class AssetCondition extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'asset_conditions';

    protected $fillable = [
        'rental_id',
        'asset_id',
        'inventory_item_id',
        'type',
        'condition',
        'notes',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
        ];
    }

    public function setRentalIdAttribute(
        mixed $value
    ): void {
        $this->attributes['rental_id'] =
            $value instanceof ObjectId
                ? $value
                : new ObjectId(
                    (string) $value
                );
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
