<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Coleccion Mongo: security_events (Modulo 1.7 - Alertas de acceso)
 *
 * Log append-only de la bitacora de seguridad: login, nuevos
 * dispositivos, revocaciones de sesion, generacion/validacion de QR,
 * intentos de reautenticacion, etc.
 */
class SecurityEvent extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'security_events';

    protected $fillable = [
        'user_id',
        'device_id',
        'session_id',
        'type',
        'severity', // info, warning, critical
        'ip_address',
        'user_agent',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public static function log(array $attributes): self
    {
        $attributes['occurred_at'] ??= now();

        return static::create($attributes);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
