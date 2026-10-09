<?php

namespace App\Models\StudentServices\ServiceAccess;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Modulo 5.11 - Cada escaneo NFC/QR/manual en un punto de servicio,
 * autorizado o denegado. Nunca se borra: es la bitácora de uso.
 */
class ServiceCheckin extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'service_checkins';

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
