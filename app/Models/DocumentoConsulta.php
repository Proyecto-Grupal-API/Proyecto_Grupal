<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/** Lectura de colecciones cuyos flujos completos se implementarán en entregas posteriores. */
class DocumentoConsulta extends Documento
{
    public static function en(string $coleccion): Builder
    {
        return (new static)->setTable($coleccion)->newQuery();
    }
}
