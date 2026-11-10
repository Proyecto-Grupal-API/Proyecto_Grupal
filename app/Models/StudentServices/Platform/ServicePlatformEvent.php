<?php

namespace App\Models\StudentServices\Platform;

use Carbon\CarbonInterface;
use MongoDB\Laravel\Eloquent\Model;

/**
 * Salida persistente de eventos analíticos del Equipo 5 hacia la
 * plataforma del Equipo 7 (REQ-M5-E7-001, POST /api/v1/plataforma/eventos).
 *
 * El sobre se guarda completo al registrar el hecho: los reintentos envían
 * exactamente el mismo contenido con el mismo event_id, que es lo que el
 * receptor usa para deduplicar (repetición idéntica = 200 duplicado,
 * contenido distinto = 409).
 *
 * Estados: pending (por enviar o reintentar), sent (acuse 200/202 con el
 * mismo event_id) y rejected (422/409/413: no se reintenta sin revisión).
 *
 * @property-read string $id
 * @property string $event_id
 * @property string $event_name
 * @property string $aggregate_id
 * @property string $occurred_at
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property CarbonInterface|null $next_attempt_at
 * @property string|null $last_error
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ServicePlatformEvent extends Model
{
    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const REJECTED = 'rejected';

    protected $connection = 'mongodb';

    protected $table = 'service_platform_events';

    protected $fillable = [
        'event_id',
        'event_name',
        'aggregate_id',
        'occurred_at',
        'payload',
        'status',
        'attempts',
        'next_attempt_at',
        'last_error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Sobre común del Equipo 7, en el orden documentado.
     *
     * @return array{event_id: string, event_name: string, source_domain: string, aggregate_id: string, occurred_at: string, payload: array<string, mixed>}
     */
    public function envelope(): array
    {
        return [
            'event_id' => $this->event_id,
            'event_name' => $this->event_name,
            'source_domain' => 'services',
            'aggregate_id' => $this->aggregate_id,
            'occurred_at' => $this->occurred_at,
            'payload' => (array) $this->payload,
        ];
    }
}
