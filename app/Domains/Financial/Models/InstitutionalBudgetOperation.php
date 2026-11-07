<?php

namespace App\Domains\Financial\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class InstitutionalBudgetOperation extends Model
{
    protected $connection = 'sqlsrv';
    protected $table = 'institutional_budget_operations';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['before_data' => 'array', 'after_data' => 'array', 'created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(function () { throw new LogicException('El historial presupuestario es inmutable.'); });
        static::deleting(function () { throw new LogicException('El historial presupuestario es inmutable.'); });
    }
}
