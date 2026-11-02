<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Coleccion Mongo: devices (Modulo 1.7 - Dispositivos y sesiones confiables)
 *
 * Un "Device" es la huella logica de un navegador/equipo desde el que
 * el estudiante ha iniciado sesion (fingerprint por user agent + IP).
 */
class Device extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'devices';

    protected $fillable = [
        'user_id',
        'fingerprint',
        'device_name',
        'device_type',
        'platform',
        'browser',
        'is_trusted',
        'first_seen_at',
        'last_seen_at',
        'last_ip_address',
        'revoked_at',
    ];

    protected $casts = [
        'is_trusted' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sessions()
    {
        return $this->hasMany(UserSession::class, 'device_id');
    }
}
