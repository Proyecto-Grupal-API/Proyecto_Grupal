<?php

namespace App\Models\StudentServices\Rentals;

use MongoDB\Laravel\Eloquent\Model;

class Asset extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'assets';

    protected $fillable = [
        'inventory_item_id',
        'name',
        'category',
        'location',
        'status',
        'description',
    ];
}
