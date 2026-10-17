<?php

namespace App\Models;

class RecomendacionBeca extends Documento
{
    protected $table = 'recomendaciones_becas';

    protected $fillable = ['organizacion_id', 'convocatoria_id', 'solicitud_id', 'usuario_id', 'version_comite', 'revision', 'recomendacion', 'motivo'];

    protected function casts(): array
    {
        return ['version_comite' => 'integer', 'revision' => 'integer'];
    }
}
