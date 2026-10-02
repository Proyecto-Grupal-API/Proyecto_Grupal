<?php

namespace App\Models\StudentServices\Reservations;

use MongoDB\Laravel\Eloquent\Model;

class Facility extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'facilities';

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
