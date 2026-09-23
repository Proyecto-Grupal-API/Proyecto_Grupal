<?php

namespace App\Models;

class AsignacionBeneficio extends Documento
{
    protected $table = 'asignaciones_beneficios';

    protected $fillable = ['organizacion_id', 'convocatoria_id', 'solicitud_id', 'usuario_id', 'estado', 'contrato'];

    protected function casts(): array
    {
        return [];
    }
}
