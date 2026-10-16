<?php

namespace App\Models;

class IntegranteComision extends Documento
{
    protected $table = 'integrantes_comision';

    protected $fillable = ['organizacion_id', 'comision_id', 'usuario_id', 'funcion', 'clave_solicitud', 'huella', 'asignado_por', 'retirado_en', 'retirado_por', 'motivo_retiro'];

    protected $hidden = ['clave_solicitud', 'huella'];

    protected function casts(): array
    {
        return ['retirado_en' => 'datetime'];
    }
}
