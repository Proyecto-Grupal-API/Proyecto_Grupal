<?php

namespace App\Models;

class Organizacion extends Documento
{
    protected $table = 'organizaciones';

    protected $fillable = ['slug', 'nombre', 'tipo', 'descripcion', 'email', 'telefono', 'estado', 'clave_alta', 'firma_alta', 'alta_datos', 'creado_por', 'version_estado', 'historial_estados'];

    protected $hidden = ['alta_datos', 'clave_alta', 'firma_alta', 'historial_estados'];
}
