<?php

namespace App\Domains\Financial\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Issuance evidence. Consumption and release are intentionally not enabled in this phase. */
final class BonusBudgetCommitment extends Model
{
    protected $connection = 'sqlsrv';
    protected $table = 'bonus_budget_commitments';
    protected $guarded = ['id'];
    protected $casts = ['amount_cents' => 'integer'];
    protected static function booted(): void
    {
        static::updating(function () { throw new LogicException('El compromiso de emision es inmutable.'); });
        static::deleting(function () { throw new LogicException('El compromiso de emision es inmutable.'); });
    }
}
