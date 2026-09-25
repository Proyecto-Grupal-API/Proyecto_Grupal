<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class PointCampaign extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'point_campaigns';

    protected $fillable = [
        'name',
        'description',
        'type',
        'sponsor_type',
        'sponsor_id',
        'multiplier',
        'starts_at',
        'ends_at',
        'status',
        'scope',
        'scope_ids',
    ];

    protected $casts = [
        'multiplier' => 'float',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'scope_ids' => 'array',
    ];
}
