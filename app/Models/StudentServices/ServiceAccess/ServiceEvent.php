<?php

namespace App\Models\StudentServices\ServiceAccess;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Modulo 5.11 - Evento de servicio publicado cuando una validación
 * produce un efecto (préstamo creado, check-in, devolución, entrega...).
 * Es el flujo que puede consumir el Equipo 7 para auditoría y analítica.
 */
class ServiceEvent extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'service_events';

    protected $fillable = [
        'event_type',
        'service',
        'action',
        'student_id',
        'reference',
        'reference_id',
        'checkin_id',
        'actor_id',
        'data',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
