<?php

namespace App\Models\StudentServices\Rentals;

use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

class Rental extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'rentals';

    protected $fillable = [
        'asset_id',
        'inventory_item_id',
        'student_id',
        'requested_at',
        'due_at',
        'returned_at',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'due_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
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
