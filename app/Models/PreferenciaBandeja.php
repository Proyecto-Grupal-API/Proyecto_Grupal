<?php

namespace App\Models;

class PreferenciaBandeja extends Documento
{
    protected $table = 'preferencias_bandeja';
    protected $fillable = ['usuario_id', 'categoria', 'silenciada'];

    protected function casts(): array
    {
        return ['silenciada' => 'boolean'];
    }
}
