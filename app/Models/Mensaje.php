<?php

namespace App\Models;

class Mensaje extends Documento
{
    protected $table = 'mensajes';

    protected $fillable = ['campaña_id', 'organizacion_id', 'usuario_id', 'asunto', 'cuerpo', 'emisor_nombre', 'accion_url', 'texto_accion', 'leido_en', 'primera_lectura_en', 'archivado_en', 'importante', 'eliminado_en'];

    protected function casts(): array
    {
        return ['leido_en' => 'datetime', 'primera_lectura_en' => 'datetime', 'archivado_en' => 'datetime', 'eliminado_en' => 'datetime', 'importante' => 'boolean'];
    }
}
