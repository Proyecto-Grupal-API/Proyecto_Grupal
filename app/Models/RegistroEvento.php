<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class RegistroEvento extends Documento
{
    use HasFactory;

    protected $connection = 'mongodb';

    protected $table = 'registros_eventos';

    protected $fillable = [
        'generacion', 'solicitudes_versionadas', 'reserva_hasta', 'vencido_en', 'estado', 'monto_centavos', 'moneda', 'cancelado_en', 'confirmado_en', 'asistio_en', 'asistencia_por',
        'evento_id',
        'usuario_id',
        'estado_pago',
        'token_qr',
        'estado_asistencia',
        'registrado_en',
        'actualizado_en',
    ];

    protected $hidden = ['token_qr'];

    public function getEstadoEfectivoAttribute(): string
    {
        return $this->estado === 'confirmada' && $this->estado_pago === 'pendiente'
            && $this->estado_asistencia !== 'asistio' && $this->reserva_hasta?->lte(now())
            ? 'vencida' : $this->estado;
    }

    public function scopeConCupo($q, $corte = null)
    {
        return $q->where('estado', 'confirmada')->where(fn ($q) => $q
            ->where('estado_pago', '!=', 'pendiente')->orWhere('estado_asistencia', 'asistio')
            ->orWhereNull('reserva_hasta')->orWhere('reserva_hasta', '>', $corte ?? now()));
    }

    public function scopePendientesDeVencer($q)
    {
        return $q->where('estado', 'confirmada')->where('estado_pago', 'pendiente')
            ->where('estado_asistencia', '!=', 'asistio')
            ->where(fn ($q) => $q->whereNull('reserva_hasta')->orWhere('reserva_hasta', '<=', now()));
    }

    public function scopeVencidas($q)
    {
        return $q->where(fn ($q) => $q->where('estado', 'vencida')
            ->orWhere(fn ($q) => $q->pendientesDeVencer()->whereNotNull('reserva_hasta')));
    }

    protected function casts(): array
    {
        return ['generacion' => 'integer', 'solicitudes_versionadas' => 'boolean', 'reserva_hasta' => 'datetime', 'vencido_en' => 'datetime', 'registrado_en' => 'datetime', 'cancelado_en' => 'datetime', 'confirmado_en' => 'datetime', 'asistio_en' => 'datetime', 'monto_centavos' => 'integer'];
    }
}
