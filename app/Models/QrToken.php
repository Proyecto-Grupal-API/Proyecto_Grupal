<?php

namespace App\Models;

use Illuminate\Support\Str;
use MongoDB\Laravel\Eloquent\Model;

/**
 * Coleccion Mongo: qr_tokens (Modulo 1.6 - Identidad QR)
 *
 * type:
 *   - identification: QR "fijo" de identificacion visible en el perfil.
 *   - dynamic: QR rotativo de corta duracion para validar operaciones
 *              sensibles (servicios, eventos, cajas).
 *
 * El token nunca contiene saldo ni datos sensibles: solo un codigo
 * opaco (code) que el backend resuelve contra la identidad real.
 */
class QrToken extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'qr_tokens';

    protected $fillable = [
        'user_id',
        'code',
        'code_hash',
        'code_encrypted',
        'short_code',
        'short_code_hash',
        'short_code_claimed',
        'type',
        'purpose',
        'expires_at',
        'consumed_at',
        'revoked_at',
    ];

    protected $hidden = [
        'code',
        'short_code',
        'code_hash',
        'short_code_hash',
        'short_code_claimed',
        'code_encrypted',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function validations()
    {
        return $this->hasMany(QrValidation::class, 'qr_token_id');
    }

    public static function generateCode(): string
    {
        return Str::upper(Str::random(8)).'-'.now()->format('His').'-'.random_int(100, 999);
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isUsable(): bool
    {
        return ! $this->isExpired() && ! $this->isConsumed() && ! $this->isRevoked();
    }

    public function secondsRemaining(): int
    {
        if ($this->expires_at === null) {
            return 0;
        }

        $diff = $this->expires_at->getTimestamp() - now()->getTimestamp();

        return max(0, $diff);
    }
}
