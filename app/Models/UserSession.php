<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Coleccion Mongo: sessions (Modulo 1.7 - Dispositivos y sesiones confiables)
 *
 * Cada documento representa una sesion iniciada desde un dispositivo.
 * Se identifica ante el navegador mediante un token opaco guardado en
 * la sesion nativa de Laravel (clave "cd_session_id"). Revocar =
 * marcar revoked_at; en el siguiente request de ese navegador el
 * middleware EnsureSessionIsActive fuerza el logout.
 *
 * Nota: la clase se llama "UserSession" (no "Session") para evitar
 * ambiguedad con clases nativas de Laravel, pero la coleccion Mongo
 * si se llama "sessions", tal como documenta el README del proyecto.
 */
class UserSession extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'sessions';

    protected $fillable = [
        'user_id',
        'device_id',
        'ip_address',
        'user_agent',
        'location_label',
        'started_at',
        'last_activity_at',
        'revoked_at',
        'revoked_reason',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
