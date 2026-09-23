<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Coleccion Mongo: qr_validations (Modulo 1.6)
 *
 * Bitacora de cada intento de validacion de un QR: el "contrato" que
 * consumen otros dominios (Equipos 2, 5, 6) cuando validan identidad
 * por QR en servicios, eventos o cajas.
 */
class QrValidation extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'qr_validations';

    protected $fillable = [
        'qr_token_id',
        'user_id',
        'validated_by_user_id',
        'validator_label', // p.ej. "Biblioteca Central - Terminal 3" (no siempre hay un User validador)
        'result', // valid, expired, consumed, revoked, not_found, invalid_signature
        'context', // p.ej. "biblioteca", "evento-123", "caja-asociacion-2"
        'ip_address',
    ];

    public function qrToken()
    {
        return $this->belongsTo(QrToken::class, 'qr_token_id');
    }

    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by_user_id');
    }
}
