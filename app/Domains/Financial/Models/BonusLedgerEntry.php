<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\BonusMovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BonusLedgerEntry extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'bonus_ledger_entries';

    protected $fillable = [
        'public_id',
        'bonus_id',
        'movement_type',
        'amount_cents',
        'remaining_after_cents',
        'reference_type',
        'reference_id',
        'actor_id',
        'reason',
    ];

    protected $casts = [
        'movement_type' => BonusMovementType::class,
        'amount_cents' => 'integer',
        'remaining_after_cents' => 'integer',
    ];

    public function bonus(): BelongsTo
    {
        return $this->belongsTo(
            Bonus::class,
            'bonus_id',
            'public_id'
        );
    }
}