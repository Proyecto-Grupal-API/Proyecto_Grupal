<?php

namespace App\Models\StudentServices\ServiceAccess;

use Carbon\CarbonInterface;
use MongoDB\Laravel\Eloquent\Model;

/**
 * Modulo 5.11 - Evento de servicio publicado cuando una validación
 * produce un efecto (préstamo creado, check-in, devolución, entrega...).
 * Es el flujo que puede consumir el Equipo 7 para auditoría y analítica.
 *
 * @property-read string $id
 * @property string $event_type
 * @property string $service
 * @property string $action
 * @property string|null $student_id
 * @property string|null $reference
 * @property string|null $reference_id
 * @property string $checkin_id
 * @property string|null $actor_id
 * @property array<string, mixed>|null $data
 * @property CarbonInterface|null $occurred_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ServiceEvent extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'service_events';

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
