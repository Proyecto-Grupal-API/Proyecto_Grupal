<?php

namespace App\Models\StudentServices\Rentals;

use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

class AssetCondition extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'asset_conditions';

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
        $value
    ): void {
        $this->attributes['rental_id'] =
            $value instanceof ObjectId
                ? $value
                : new ObjectId(
                (string) $value
            );
    }

    public function setAssetIdAttribute(
        $value
    ): void {
        $this->attributes['asset_id'] =
            $value instanceof ObjectId
                ? $value
                : new ObjectId(
                (string) $value
            );
    }
}
