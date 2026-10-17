<?php

namespace App\Models;

class SolicitudReservaEvento extends Documento
{
    protected $table = 'solicitudes_reservas_eventos';

    protected $fillable = ['usuario_id', 'clave_solicitud', 'evento_id', 'accion', 'generacion_esperada', 'generacion_resultado'];

    protected $hidden = ['clave_solicitud'];

    protected function casts(): array
    {
        return ['generacion_esperada' => 'integer', 'generacion_resultado' => 'integer'];
    }
}
