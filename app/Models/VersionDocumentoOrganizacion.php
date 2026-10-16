<?php

namespace App\Models;

class VersionDocumentoOrganizacion extends Documento
{
    protected $table = 'versiones_documentos_organizacion';

    protected $fillable = ['organizacion_id', 'documento_id', 'numero', 'nombre', 'tipo', 'tamano', 'ruta', 'sha256', 'motivo', 'cargado_por', 'clave_solicitud', 'huella'];

    protected $hidden = ['ruta', 'clave_solicitud', 'huella'];

    protected function casts(): array
    {
        return ['numero' => 'integer', 'tamano' => 'integer'];
    }
}
