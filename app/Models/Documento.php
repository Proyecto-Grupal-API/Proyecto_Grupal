<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

abstract class Documento extends Model
{
    protected $connection = 'mongodb';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';
}
