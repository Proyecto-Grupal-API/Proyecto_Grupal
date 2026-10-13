<?php

namespace App\Models\StudentServices\Rentals;

use Carbon\CarbonInterface;
use MongoDB\Laravel\Eloquent\Model;

/**
 * @property-read string $id
 * @property string|null $inventory_item_id
 * @property string $name
 * @property string|null $category
 * @property string|null $location
 * @property string $status
 * @property string|null $description
 * @property int|null $deposit_cents
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Asset extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'assets';

    protected $fillable = [
        'inventory_item_id',
        'name',
        'category',
        'location',
        'status',
        'description',
        'deposit_cents',
    ];

    protected function casts(): array
    {
        return [
            'deposit_cents' => 'integer',
        ];
    }

    /**
     * Depósito en garantía que se retiene al rentar (0 = sin depósito).
     */
    public function depositCents(): int
    {
        return (int) ($this->deposit_cents ?? 0);
    }
}
