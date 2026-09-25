<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class EarningRule extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'earning_rules';

    protected $fillable = [
        'business_id',
        'name',
        'type',
        'points_per_unit',
        'unit_amount',
        'fixed_points',
        'multiplier',
        'excluded_products',
        'max_points_per_operation',
        'daily_cap',
        'active_from',
        'active_to',
        'status',
    ];

    protected $casts = [
        'points_per_unit' => 'integer',
        'unit_amount' => 'float',
        'fixed_points' => 'integer',
        'multiplier' => 'float',
        'excluded_products' => 'array',
        'max_points_per_operation' => 'integer',
        'daily_cap' => 'integer',
        'active_from' => 'datetime',
        'active_to' => 'datetime',
    ];

    public function isActiveOn(\DateTimeInterface $date): bool
    {
        if ($this->status !== 'active') return false;
        if ($this->active_from && $date < $this->active_from) return false;
        if ($this->active_to && $date > $this->active_to) return false;
        return true;
    }
}
