<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class MiembroOrganizacion extends Documento
{
    protected $table = 'miembros_organizacion';

    protected $fillable = ['organizacion_id', 'usuario_id', 'fecha_inicio', 'estado', 'eliminado_en'];

    protected function casts(): array
    {
        return ['fecha_inicio' => 'datetime', 'eliminado_en' => 'datetime'];
    }

    public function scopeActivos(Builder $query): void
    {
        $query->where('estado', 'activo')->whereNull('eliminado_en')->where('fecha_inicio', '<=', now());
    }
}
