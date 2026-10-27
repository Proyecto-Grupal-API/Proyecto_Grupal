<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\LimitAction;
use App\Domains\Financial\Enums\LimitMetric;
use App\Domains\Financial\Enums\LimitPeriod;
use App\Domains\Financial\Enums\LimitSubjectType;
use App\Domains\Financial\Enums\MovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialLimit extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'financial_limits';

    protected $fillable = [
        'public_id',
        'name',
        'subject_type',
        'subject_id',
        'operation',
        'period',
        'metric',
        'max_amount_cents',
        'max_count',
        'action',
        'currency',
        'active',
        'valid_from',
        'valid_until',
        'created_by',
        'updated_by',
        'reason',
    ];

    protected $casts = [
        'subject_type' => LimitSubjectType::class,
        'operation' => MovementType::class,
        'period' => LimitPeriod::class,
        'metric' => LimitMetric::class,
        'action' => LimitAction::class,
        'max_amount_cents' => 'integer',
        'max_count' => 'integer',
        'active' => 'boolean',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
    ];

    /**
     * Valor máximo permitido según la métrica (centavos o número de
     * operaciones).
     */
    public function threshold(): int
    {
        return $this->metric === LimitMetric::MONTO
            ? (int) $this->max_amount_cents
            : (int) $this->max_count;
    }

    public function changes(): HasMany
    {
        return $this->hasMany(
            FinancialLimitChange::class,
            'limit_id',
            'public_id'
        );
    }

    /**
     * Snapshot de lo que define la política (para el historial).
     */
    public function snapshot(): array
    {
        return [
            'name' => $this->name,
            'subject_type' => $this->subject_type?->value,
            'subject_id' => $this->subject_id,
            'operation' => $this->operation?->value,
            'period' => $this->period?->value,
            'metric' => $this->metric?->value,
            'max_amount_cents' => $this->max_amount_cents,
            'max_count' => $this->max_count,
            'action' => $this->action?->value,
            'currency' => $this->currency,
            'active' => (bool) $this->active,
            'valid_from' => $this->valid_from?->toISOString(),
            'valid_until' => $this->valid_until?->toISOString(),
            'reason' => $this->reason,
        ];
    }
}
