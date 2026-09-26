<?php

namespace App\Models;

class AuditoriaComunidad extends Documento
{
    protected $table = 'auditoria_comunidad';

    protected $fillable = ['organizacion_id', 'usuario_id', 'accion', 'entidad_id', 'antes', 'despues'];
}
