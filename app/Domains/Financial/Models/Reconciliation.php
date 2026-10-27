<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\CashReconciliationStatus;
use App\Domains\Financial\Enums\ReconciliationScope;
use App\Domains\Financial\Enums\ReconciliationStatus;
use App\Domains\Financial\Enums\ReconciliationTrigger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reconciliation extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'reconciliations';

    protected $fillable = [
        'public_id',
        'business_date',
        'timezone',
        'scope',
        'scope_wallet_ids',
        'status',
        'started_at',
        'finished_at',
        'executed_by',
        'checks_executed',
        'differences_count',
        'summary',
        'error_message',
        'trigger_source',
        'attempt',
        'idempotency_key',
        'cash_status',
        'new_differences_count',
        'recurring_differences_count',
    ];

    protected $casts = [
        'business_date' => 'date',
        'scope' => ReconciliationScope::class,
        'status' => ReconciliationStatus::class,
        'scope_wallet_ids' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'checks_executed' => 'integer',
        'differences_count' => 'integer',
        'summary' => 'array',
        'trigger_source' => ReconciliationTrigger::class,
        'cash_status' => CashReconciliationStatus::class,
        'attempt' => 'integer',
        'new_differences_count' => 'integer',
        'recurring_differences_count' => 'integer',
    ];

    /**
     * Diferencias detectadas por PRIMERA vez en esta corrida.
     */
    public function differences(): HasMany
    {
        return $this->hasMany(
            ReconciliationDifference::class,
            'reconciliation_id',
            'public_id'
        );
    }

    /**
     * Todas las diferencias que esta corrida observó (nuevas o ya
     * conocidas). Las corridas previas a la tabla de observaciones solo
     * tienen differences().
     */
    public function observedDifferences(): Builder
    {
        $observed = ReconciliationDifferenceObservation::query()
            ->select('difference_id')
            ->where('reconciliation_id', $this->public_id);

        return ReconciliationDifference::query()
            ->where(function (Builder $q) use ($observed) {
                $q->whereIn('public_id', $observed)
                    ->orWhere('reconciliation_id', $this->public_id);
            });
    }
}
