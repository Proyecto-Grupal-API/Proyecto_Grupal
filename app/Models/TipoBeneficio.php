<?php

namespace App\Models;

class TipoBeneficio extends Documento
{
    protected $table = 'tipos_beneficio';

    protected $fillable = ['slug', 'nombre', 'es_monetario', 'es_servicio', 'equipo'];

    protected function casts(): array
    {
        return ['es_monetario' => 'boolean', 'es_servicio' => 'boolean', 'equipo' => 'integer'];
    }
}
