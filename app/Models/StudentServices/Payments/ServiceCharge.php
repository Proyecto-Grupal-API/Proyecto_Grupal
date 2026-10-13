<?php

namespace App\Models\StudentServices\Payments;

use Carbon\CarbonInterface;
use MongoDB\Laravel\Eloquent\Model;

/**
 * Registro de cada movimiento de dinero que pide el Equipo 5 (retener,
 * cobrar o liberar). Mientras el Equipo 2 publica su API de cobros
 * (REQ-M5-E2-001) el proveedor es `local`: queda la evidencia, pero el saldo
 * de la wallet no cambia.
 *
 * @property-read string $id
 * @property string $reference
 * @property string $operation
 * @property string $provider
 * @property string $student_id
 * @property string|null $concept
 * @property string|null $source_reference
 * @property string|null $hold_reference
 * @property int $amount_cents
 * @property string|null $reason
 * @property string $idempotency_key
 * @property string $status
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ServiceCharge extends Model
{
    public const OPERATIONS = ['hold', 'capture', 'release'];

    protected $connection = 'mongodb';

    protected $table = 'service_charges';

    protected $fillable = [
        'reference',
        'operation',
        'provider',
        'student_id',
        'concept',
        'source_reference',
        'hold_reference',
        'amount_cents',
        'reason',
        'idempotency_key',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
        ];
    }
}
