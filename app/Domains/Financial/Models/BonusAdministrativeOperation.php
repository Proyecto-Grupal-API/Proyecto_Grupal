<?php

namespace App\Domains\Financial\Models;

use Illuminate\Database\Eloquent\Model;

class BonusAdministrativeOperation extends Model
{
    protected $connection = 'sqlsrv';
    protected $table = 'bonus_administrative_operations';
    public $timestamps = false;
    protected $fillable = ['public_id', 'bonus_id', 'action', 'actor_id', 'idempotency_key',
        'request_hash', 'reason', 'request_data', 'before_data', 'after_data', 'created_at'];
    protected $casts = ['request_data' => 'array', 'before_data' => 'array', 'after_data' => 'array', 'created_at' => 'datetime'];
}
