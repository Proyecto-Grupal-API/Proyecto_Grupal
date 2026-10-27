<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\DifferenceStatus;
use App\Domains\Financial\Enums\ReconciliationCheck;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationDifference extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'reconciliation_differences';

    protected $fillable = [
        'public_id',
        'reconciliation_id',
        'check_code',
        'entity_type',
        'entity_id',
        'wallet_id',
        'expected_cents',
        'actual_cents',
        'difference_cents',
        'details',
        'status',
        'resolved_by',
        'resolved_at',
        'resolution_note',
        'fingerprint',
        'business_date',
        'last_seen_reconciliation_id',
        'last_seen_at',
        'occurrences',
    ];

    protected $casts = [
        'check_code' => ReconciliationCheck::class,
        'status' => DifferenceStatus::class,
        'expected_cents' => 'integer',
        'actual_cents' => 'integer',
        'difference_cents' => 'integer',
        'details' => 'array',
        'resolved_at' => 'datetime',
        'business_date' => 'date',
        'last_seen_at' => 'datetime',
        'occurrences' => 'integer',
    ];

    /**
     * La diferencia se volvió a detectar después de documentarse como
     * resuelta (resolver solo documenta; no corrige nada).
     */
    public function seenAfterResolution(): bool
    {
        return $this->resolved_at !== null
            && $this->last_seen_at !== null
            && $this->last_seen_at->greaterThan($this->resolved_at);
    }

    public function wasObservedIn(string $reconciliationId): bool
    {
        if (strtolower((string) $this->reconciliation_id) === strtolower($reconciliationId)) {
            return true;
        }

        return ReconciliationDifferenceObservation::query()
            ->where('reconciliation_id', $reconciliationId)
            ->where('difference_id', $this->public_id)
            ->exists();
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(
            Reconciliation::class,
            'reconciliation_id',
            'public_id'
        );
    }
}
