<?php

namespace App\Models;

class Campana extends Documento
{
    protected $table = 'campañas';

    protected $fillable = ['referencia_demo', 'organizacion_id', 'nombre', 'asunto', 'cuerpo', 'audiencia', 'referencia_id', 'accion', 'texto_accion', 'estado', 'revision', 'destinatarios', 'total_destinatarios', 'procesados', 'omitidos', 'enviado_por', 'emisor_nombre', 'ejecucion', 'encolado_en', 'enviado_en', 'error', 'motivo_cancelacion'];

    protected $hidden = ['destinatarios', 'ejecucion'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'total_destinatarios' => 'integer', 'procesados' => 'integer', 'omitidos' => 'integer', 'encolado_en' => 'datetime', 'enviado_en' => 'datetime'];
    }
}
