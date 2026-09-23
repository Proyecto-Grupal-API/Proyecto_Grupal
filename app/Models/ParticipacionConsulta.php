<?php

namespace App\Models;

abstract class ParticipacionConsulta extends Documento
{
    protected $fillable = ['consulta_id', 'organizacion_id', 'usuario_id', 'respuestas'];

    protected $hidden = ['usuario_id', 'respuestas'];
}
