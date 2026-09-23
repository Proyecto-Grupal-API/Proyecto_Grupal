<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class RegistroEvento extends Documento
{
    use HasFactory;

    protected $connection = 'mongodb';

    protected $table = 'registros_eventos';

    protected $fillable = [
        'estado', 'monto_centavos', 'moneda', 'cancelado_en', 'confirmado_en', 'asistio_en', 'asistencia_por',
        'evento_id',
        'usuario_id',
        'estado_pago',
        'token_qr',
        'estado_asistencia',
        'registrado_en',
        'actualizado_en',
    ];

    protected $hidden = ['token_qr'];

    protected function casts(): array
    {
        return ['registrado_en' => 'datetime', 'cancelado_en' => 'datetime', 'confirmado_en' => 'datetime', 'asistio_en' => 'datetime', 'monto_centavos' => 'integer'];
    }
}
