<?php

namespace App\Models;

class SolicitudBeca extends Documento
{
    protected $table = 'solicitudes_becas';

    protected $fillable = ['organizacion_id', 'convocatoria_id', 'usuario_id', 'folio', 'motivacion', 'documentos', 'estado', 'enviado_en', 'dictamen', 'dictaminado_en', 'dictaminado_por', 'motivo_cancelacion'];

    protected $hidden = ['documentos'];

    protected function casts(): array
    {
        return ['enviado_en' => 'datetime', 'dictaminado_en' => 'datetime'];
    }
}
