<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organizacion extends Model
{
    // 1. El nombre exacto de tu tabla en SQL Server
    protected $table = 'organizaciones'; 

    // 2. Laravel busca 'created_at', le decimos que usas 'creado_en'
    const CREATED_AT = 'creado_en';
    const UPDATED_AT = 'actualizado_en';
}