<?php

namespace App\Models\PlatformModule;

use MongoDB\Laravel\Eloquent\Model;

class Setting extends Model
{
    protected $connection = 'platform';

    protected $collection = 'settings';

    protected $fillable = [
        'key',
        'value',
        'type',
        'description',
        'is_sensitive',
    ];

    protected $casts = [
        'value' => 'array',
        'is_sensitive' => 'boolean',
    ];
}
