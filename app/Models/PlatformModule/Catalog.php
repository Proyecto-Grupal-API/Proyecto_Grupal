<?php

namespace App\Models\PlatformModule;

use MongoDB\Laravel\Eloquent\Model;

class Catalog extends Model
{
    protected $connection = 'platform';

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
