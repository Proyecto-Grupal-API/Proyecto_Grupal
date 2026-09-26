<?php

namespace App\Models;

class ConvocatoriaBeca extends Documento
{
    protected $table = 'convocatorias_becas';

    protected $fillable = ['referencia_demo', 'organizacion_id', 'creado_por', 'titulo', 'descripcion', 'requisitos', 'tipo_beneficio_id', 'beneficio', 'monto_centavos', 'cantidad', 'total_espacios', 'fecha_inicio', 'fecha_fin', 'vigencia_inicio', 'vigencia_fin', 'requisitos_documentos', 'requiere_documentos', 'estado', 'motivo_cancelacion'];

    protected function casts(): array
    {
        return ['fecha_inicio' => 'datetime', 'fecha_fin' => 'datetime', 'vigencia_inicio' => 'datetime', 'vigencia_fin' => 'datetime', 'total_espacios' => 'integer', 'monto_centavos' => 'integer', 'cantidad' => 'integer', 'requiere_documentos' => 'boolean'];
    }
}
