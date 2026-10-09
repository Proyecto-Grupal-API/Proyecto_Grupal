<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\AlertOutcome;
use App\Domains\Financial\Enums\AlertStatus;
use App\Domains\Financial\Enums\AlertType;
use App\Domains\Financial\Enums\LimitAction;
use App\Domains\Financial\Enums\LimitMetric;
use App\Domains\Financial\Enums\LimitPeriod;
use App\Domains\Financial\Enums\MovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransactionAlert extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'transaction_alerts';

    protected $fillable = [
        'public_id',
        'dedupe_key',
        'alert_type',
        'status',
        'limit_id',
        'wallet_id',
        'transaction_id',
        'ledger_entry_id',
        'operation',
        'reference_type',
        'reference_id',
        'amount_cents',
        'limit_period',
        'limit_metric',
        'limit_action',
        'observed_value',
        'threshold_value',
        'details',
        'detected_at',
        'status_changed_by',
        'status_changed_at',
        'resolution_note',
        'correlation_id',
        'outcome',
        'period_start',
        'period_end',
    ];

    protected $casts = [
        'alert_type' => AlertType::class,
        'status' => AlertStatus::class,
        'operation' => MovementType::class,
        'limit_period' => LimitPeriod::class,
        'limit_metric' => LimitMetric::class,
        'limit_action' => LimitAction::class,
        'amount_cents' => 'integer',
        'observed_value' => 'integer',
        'threshold_value' => 'integer',
        'details' => 'array',
        'detected_at' => 'datetime',
        'status_changed_at' => 'datetime',
        'outcome' => AlertOutcome::class,
        'period_start' => 'datetime',
        'period_end' => 'datetime',
    ];

    public function limit(): BelongsTo
    {
        return $this->belongsTo(
            FinancialLimit::class,
            'limit_id',
            'public_id'
        );
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(
            TransactionAlertStatusChange::class,
            'alert_id',
            'public_id'
        );
    }
}
