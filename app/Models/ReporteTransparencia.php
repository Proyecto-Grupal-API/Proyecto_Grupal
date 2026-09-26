<?php

namespace App\Models;

class ReporteTransparencia extends Documento
{
    protected $table = 'reportes_transparencia';

    protected $fillable = ['organizacion_id', 'organizacion_nombre', 'clave_solicitud', 'version', 'titulo', 'descripcion', 'fecha_inicio', 'fecha_fin', 'generado_en', 'metricas', 'estado', 'publicado_en', 'retirado_en', 'motivo_retiro'];

    protected $hidden = ['clave_solicitud'];

    protected function casts(): array
    {
        return ['generado_en' => 'datetime', 'publicado_en' => 'datetime', 'retirado_en' => 'datetime', 'version' => 'integer'];
    }
}
