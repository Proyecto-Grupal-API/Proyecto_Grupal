<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class PointsPolicy extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'points_policies';

    protected $fillable = [
        'name',
        'type',
        'expiration_days',
        'expiration_batch_strategy',
        'daily_cap_points',
        'max_redemptions_per_day',
        'reversal_grace_period_days',
        'status',
        'config',
    ];

    protected $casts = [
        'expiration_days' => 'integer',
        'daily_cap_points' => 'integer',
        'max_redemptions_per_day' => 'integer',
        'reversal_grace_period_days' => 'integer',
        'config' => 'array',
    ];
}
