<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\CashMovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'cash_movements';

    protected $fillable = [
        'public_id',
        'cash_shift_id',
        'type',
        'amount_cents',
        'reference_type',
        'reference_id',
        'reason', 'actor_id', 'idempotency_key', 'request_hash', 'wallet_id',
    ];

    protected $casts = [
        'type' => CashMovementType::class, 'amount_cents' => 'integer', 'cash_shift_id' => 'integer',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class, 'cash_shift_id');
    }
}
