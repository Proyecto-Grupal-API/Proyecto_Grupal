<?php

namespace App\Models;

abstract class ConsultaComunidad extends Documento
{
    protected $fillable = ['referencia_demo', 'organizacion_id', 'titulo', 'descripcion', 'fecha_inicio', 'fecha_fin', 'preguntas', 'estado', 'revision', 'padron', 'total_padron', 'publicado_en', 'cerrado_en', 'motivo_cierre', 'motivo_cancelacion'];

    protected $hidden = ['padron'];

    protected function casts(): array
    {
        return ['fecha_inicio' => 'datetime', 'fecha_fin' => 'datetime', 'publicado_en' => 'datetime', 'cerrado_en' => 'datetime', 'revision' => 'integer', 'total_padron' => 'integer'];
    }

    public function fase(): string
    {
        if ($this->estado !== 'publicada') {
            return $this->estado;
        }
        if (now()->gte($this->fecha_fin)) {
            return 'cerrada';
        }

        return now()->lt($this->fecha_inicio) ? 'programada' : 'abierta';
    }
}
