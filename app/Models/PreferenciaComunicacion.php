<?php

namespace App\Models;

class PreferenciaComunicacion extends Documento
{
    protected $table = 'preferencias_comunicacion';

    protected $fillable = ['usuario_id', 'organizacion_id', 'silenciada'];

    protected function casts(): array
    {
        return ['silenciada' => 'boolean'];
    }
}
