<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class RolOrganizacion extends Documento
{
    public const CARGOS = ['presidencia', 'vicepresidencia', 'tesoreria', 'secretaria', 'comunicacion'];

    protected $table = 'asignaciones_roles_organizacion';

    protected $fillable = ['organizacion_id', 'usuario_id', 'slug_rol', 'fecha_inicio', 'fecha_fin', 'otorgado_por', 'eliminado_en'];

    protected function casts(): array
    {
        return ['fecha_inicio' => 'datetime', 'fecha_fin' => 'datetime', 'eliminado_en' => 'datetime'];
    }

    public function scopeVigentes(Builder $query): void
    {
        $query->whereNull('eliminado_en')->where('fecha_inicio', '<=', now())
            ->where(fn ($q) => $q->whereNull('fecha_fin')->orWhere('fecha_fin', '>=', now()->startOfDay()));
    }

    public function getVigenteAttribute(): bool
    {
        return $this->eliminado_en === null && $this->fecha_inicio?->lte(now())
            && ($this->fecha_fin === null || $this->fecha_fin->gte(now()->startOfDay()));
    }
}
