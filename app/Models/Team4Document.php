<?php
namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

abstract class Team4Document extends Model
{
    protected $connection = 'mongodb';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $guarded = ['_id'];

    protected $casts = [
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
