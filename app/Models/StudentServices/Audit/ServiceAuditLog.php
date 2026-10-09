<?php

namespace App\Models\StudentServices\Audit;

use Carbon\CarbonInterface;
use MongoDB\Laravel\Eloquent\Model;

/**
 * Bitácora de operaciones sensibles del Equipo 5 (Definition of Done:
 * "operaciones sensibles generan auditoría"). Los campos siguen el formato
 * de `audit_logs` del Equipo 7 para poder enviárselos tal cual
 * (REQ-M5-E7-001); `published_at` queda en null hasta ese envío.
 *
 * @property-read string $id
 * @property string $event_id
 * @property string $source_domain
 * @property string|null $actor_id
 * @property string|null $actor_role
 * @property string $action
 * @property string $entity_type
 * @property string $entity_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $reason
 * @property string|null $correlation_id
 * @property string|null $ip_address
 * @property string|null $device
 * @property CarbonInterface|null $occurred_at
 * @property CarbonInterface|null $published_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ServiceAuditLog extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'service_audit_logs';

    protected $fillable = [
        'event_id',
        'source_domain',
        'actor_id',
        'actor_role',
        'action',
        'entity_type',
        'entity_id',
        'before',
        'after',
        'reason',
        'correlation_id',
        'ip_address',
        'device',
        'occurred_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
