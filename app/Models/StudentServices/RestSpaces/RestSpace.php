<?php

namespace App\Models\StudentServices\RestSpaces;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Modulo 5.6 - Cápsula, sillón o espacio silencioso reservable.
 *
 * Horario, franjas y tiempo máximo de uso los define su calendario
 * (módulo 5.10), no este modelo.
 */
class RestSpace extends Model
{
    public const TYPES = [
        'capsule' => 'Cápsula',
        'chair' => 'Sillón',
        'silent' => 'Espacio silencioso',
    ];

    public const STATUSES = [
        'available' => 'Disponible',
        'maintenance' => 'Mantenimiento',
    ];

    protected $connection = 'mongodb';

    protected $collection = 'rest_spaces';

    protected $fillable = [
        'code',
        'name',
        'type',
        'location',
        'capacity',
        'description',
        'status',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'active' => 'boolean',
        ];
    }
}
