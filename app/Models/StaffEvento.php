<?php

namespace App\Models;

class StaffEvento extends Documento
{
    protected $table = 'staff_eventos';

    protected $fillable = ['evento_id', 'organizacion_id', 'usuario_id', 'asignado_por', 'eliminado_en'];

    protected function casts(): array
    {
        return ['eliminado_en' => 'datetime'];
    }
}
