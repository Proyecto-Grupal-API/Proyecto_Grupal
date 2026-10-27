<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\LimitChangeType;
use Illuminate\Database\Eloquent\Model;

/**
 * 2.10 Historial de cambios de un límite (solo inserción).
 */
class FinancialLimitChange extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'financial_limit_changes';

    protected $fillable = [
        'public_id',
        'limit_id',
        'change_type',
        'actor_id',
        'reason',
        'correlation_id',
        'before',
        'after',
    ];

    protected $casts = [
        'change_type' => LimitChangeType::class,
        'before' => 'array',
        'after' => 'array',
    ];
}
