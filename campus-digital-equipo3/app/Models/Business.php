<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Business extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'businesses';

    protected $fillable = [
        'name','slug','type','owner_id','description','status','visibility',
        'verified','logo','banner','policies','hours','delivery_points',
        'contact','payment_methods','tags'
    ];

    protected $casts = [
        'verified' => 'boolean',
        'payment_methods' => 'array',
        'tags' => 'array',
        'hours' => 'array',
        'delivery_points' => 'array',
        'contact' => 'array',
    ];
}
