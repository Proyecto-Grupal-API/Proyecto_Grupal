<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Casts\AsBsonArray;
use MongoDB\Laravel\Eloquent\Model;

class ServiceClient extends Model
{
    protected $connection = 'mongodb';

    /** Colección real de los clientes de servicio. El controlador de MongoDB ignora `$collection`. */
    protected $table = 'service_clients';

    protected $fillable = ['name', 'client_id', 'secret_hash', 'scopes', 'active'];

    protected $hidden = ['secret_hash'];

    protected $casts = ['scopes' => AsBsonArray::class, 'active' => 'boolean'];
}
