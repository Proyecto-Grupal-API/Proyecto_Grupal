<?php

namespace App\Models;

class RetiroAviso extends Documento
{
    protected $table = 'retiros_avisos';
    protected $fillable = ['cliente_id', 'clave_idempotencia', 'huella', 'notificacion_id', 'resultado'];
}
