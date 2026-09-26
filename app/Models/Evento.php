<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class Evento extends Documento
{
    use HasFactory;

    protected $connection = 'mongodb';

    protected $table = 'eventos';

    protected $fillable = [
        'organizacion_id',
        'tipo_evento_id',
        'lista_espera',
        'motivo_cancelacion',
        'cancelado_en',
        'titulo',
        'slug',
        'descripcion',
        'ubicacion',
        'fecha_hora_inicio',
        'fecha_hora_fin',
        'costo',
        'capacidad',
        'fecha_inicio_registro',
        'fecha_fin_registro',
        'estado',
        'creado_por',
        'creado_en',
        'actualizado_en',
    ];

    protected function casts(): array
    {
        return ['lista_espera' => 'boolean', 'cancelado_en' => 'datetime', 'fecha_hora_inicio' => 'datetime', 'fecha_hora_fin' => 'datetime', 'fecha_inicio_registro' => 'datetime', 'fecha_fin_registro' => 'datetime', 'capacidad' => 'integer', 'costo' => 'float'];
    }
}
