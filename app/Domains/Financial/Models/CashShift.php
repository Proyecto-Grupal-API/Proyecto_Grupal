<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\CashShiftStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashShift extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'cash_shifts';

    protected $fillable = [
        'public_id',
        'cash_register_id',
        'agent_id',
        'opening_amount_cents',
        'counted_amount_cents',
        'difference_cents',
        'status',
        'opened_at',
        'closed_at', 'opening_key', 'closing_key', 'closing_reason', 'closed_by',
        'cash_in_cents', 'cash_out_cents', 'adjustment_cents', 'expected_amount_cents',
    ];

    protected $casts = [
        'status' => CashShiftStatus::class, 'cash_register_id' => 'integer',
        'opening_amount_cents' => 'integer', 'counted_amount_cents' => 'integer',
        'difference_cents' => 'integer', 'cash_in_cents' => 'integer', 'cash_out_cents' => 'integer',
        'adjustment_cents' => 'integer', 'expected_amount_cents' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }
}
