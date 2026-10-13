<?php

namespace App\Models\StudentServices\Reservations;

use Carbon\CarbonInterface;
use MongoDB\Laravel\Eloquent\Model;

/**
 * @property-read string $id
 * @property string $name
 * @property string $type
 * @property string|null $building
 * @property int $capacity
 * @property int|null $cost_cents
 * @property bool $active
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Facility extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'facilities';

    protected $fillable = [
        'name',
        'type',
        'building',
        'capacity',
        'cost_cents',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'cost_cents' => 'integer',
            'active' => 'boolean',
        ];
    }
}
