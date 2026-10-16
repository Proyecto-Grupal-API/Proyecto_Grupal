<?php

namespace App\Models;

class DocumentoOrganizacion extends Documento
{
    protected $table = 'documentos_organizacion';

    protected $fillable = ['organizacion_id', 'titulo', 'categoria', 'descripcion', 'fecha_documento', 'visibilidad', 'estado', 'version', 'numero_actual', 'version_actual_id', 'creado_por', 'clave_solicitud', 'huella', 'retirado_en', 'retirado_por', 'motivo_retiro'];

    protected $hidden = ['clave_solicitud', 'huella', 'version_actual_id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'numero_actual' => 'integer', 'retirado_en' => 'datetime'];
    }
}
