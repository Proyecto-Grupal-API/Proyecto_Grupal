<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;

class Evento extends Model
{
    use HasFactory;

    protected $connection = 'mongodb';
    protected $collection = 'eventos';

    protected $fillable = [
        'organizacion_id',
        'tipo_evento_id',
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
        'actualizado_en'
    ];
}