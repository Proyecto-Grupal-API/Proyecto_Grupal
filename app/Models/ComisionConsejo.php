<?php

namespace App\Models;

class ComisionConsejo extends Documento
{
    protected $table = 'comisiones_consejo';

    protected $fillable = ['organizacion_id', 'nombre', 'nombre_clave', 'proposito', 'fecha_inicio', 'fecha_fin', 'estado', 'version', 'creado_por', 'clave_solicitud', 'huella', 'cerrada_en', 'cerrada_por', 'motivo_cierre'];

    protected $hidden = ['nombre_clave', 'clave_solicitud', 'huella'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'cerrada_en' => 'datetime'];
    }

    public function getSituacionAttribute(): string
    {
        $hoy = now()->timezone('America/Mexico_City')->toDateString();

        return $this->estado === 'cerrada' ? 'cerrada' : ($this->fecha_inicio > $hoy ? 'programada' : ($this->fecha_fin && $this->fecha_fin < $hoy ? 'vencida' : 'vigente'));
    }
}
