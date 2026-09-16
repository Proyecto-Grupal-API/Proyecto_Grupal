<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;

class RegistroEvento extends Model
{
    use HasFactory;

    protected $connection = 'mongodb';
    protected $collection = 'registros_eventos';

    protected $fillable = [
        'evento_id',
        'usuario_id',
        'estado_pago',
        'token_qr',
        'estado_asistencia',
        'registrado_en',
        'actualizado_en'
    ];
}