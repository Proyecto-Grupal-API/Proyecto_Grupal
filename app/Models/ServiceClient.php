<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Eloquent\Casts\AsBsonArray;

class ServiceClient extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'oauth_service_clients';
    protected $fillable = ['name', 'client_id', 'secret_hash', 'scopes', 'active'];
    protected $hidden = ['secret_hash'];
    protected $casts = ['scopes' => AsBsonArray::class, 'active' => 'boolean'];
}
