<?php

namespace App\Models\StudentServices\ServiceAccess;

use Carbon\CarbonInterface;
use MongoDB\Laravel\Eloquent\Model;

/**
 * Modulo 5.11 - Cada escaneo NFC/QR/manual en un punto de servicio,
 * autorizado o denegado. Nunca se borra: es la bitácora de uso.
 *
 * @property-read string $id
 * @property string $folio
 * @property string|null $student_id
 * @property string|null $student_name
 * @property string|null $credential_hint
 * @property string $method
 * @property string $service
 * @property string $action
 * @property string|null $reference
 * @property string|null $reference_id
 * @property bool $granted
 * @property string $message
 * @property string|null $operator_id
 * @property CarbonInterface|null $scanned_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ServiceCheckin extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'service_checkins';

    protected $fillable = [
        'folio',
        'student_id',
        'student_name',
        'credential_hint',
        'method',
        'service',
        'action',
        'reference',
        'reference_id',
        'granted',
        'message',
        'operator_id',
        'scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'granted' => 'boolean',
            'scanned_at' => 'datetime',
        ];
    }
}
