<?php

namespace App\Models;

class PeriodoOrganizacion extends Documento
{
    protected $table = 'periodos_organizacion';

    protected $fillable = ['organizacion_id', 'nombre', 'nombre_clave', 'descripcion', 'fecha_inicio', 'fecha_fin', 'estado', 'version', 'creado_por', 'clave_solicitud', 'huella', 'cancelado_en', 'cancelado_por', 'motivo_cancelacion'];

    protected $hidden = ['nombre_clave', 'clave_solicitud', 'huella'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'cancelado_en' => 'datetime'];
    }

    public function getSituacionAttribute(): string
    {
        $hoy = today()->toDateString();

        return $this->estado === 'cancelado' ? 'cancelado' : ($this->fecha_inicio > $hoy ? 'programado' : ($this->fecha_fin < $hoy ? 'finalizado' : 'vigente'));
    }
}
