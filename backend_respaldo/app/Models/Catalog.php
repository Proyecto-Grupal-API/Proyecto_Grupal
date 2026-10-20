<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Catalog extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'catalogs';

    protected $fillable = [
        'name',
        'code',
        'items',
        'description',
    ];

    protected $casts = [
        'items' => 'array',
    ];
}
