<?php

namespace App\Models;

/** Aviso general a toda la comunidad, publicado por la coordinación de comunicaciones (petición del profesor, 20-nov-2026). */
class AvisoGeneral extends Documento
{
    protected $table = 'avisos_generales';

    protected $fillable = ['asunto', 'cuerpo', 'autor_id', 'autor_nombre', 'publicado_en', 'estado', 'entregados', 'ultimo_usuario', 'enviado_en', 'error'];

    protected function casts(): array
    {
        return ['publicado_en' => 'datetime', 'enviado_en' => 'datetime', 'entregados' => 'integer'];
    }
}
