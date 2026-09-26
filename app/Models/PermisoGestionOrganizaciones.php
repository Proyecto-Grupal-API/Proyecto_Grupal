<?php

namespace App\Models;

class PermisoGestionOrganizaciones extends Documento
{
    protected $table = 'permisos_gestion_organizaciones';

    protected $fillable = ['usuario_id', 'activo', 'operador', 'motivo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
