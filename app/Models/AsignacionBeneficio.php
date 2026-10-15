<?php

namespace App\Models;

class AsignacionBeneficio extends Documento
{
    protected $table = 'asignaciones_beneficios';

    protected $fillable = ['organizacion_id', 'convocatoria_id', 'solicitud_id', 'usuario_id', 'estado', 'contrato', 'recibo_servicios', 'entregado_en', 'intentos', 'proximo_intento_en', 'error_integracion'];

    protected function casts(): array
    {
        return ['entregado_en' => 'datetime', 'proximo_intento_en' => 'datetime', 'intentos' => 'integer'];
    }
}
