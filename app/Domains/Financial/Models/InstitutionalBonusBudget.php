<?php

namespace App\Domains\Financial\Models;

use Illuminate\Database\Eloquent\Model;

final class InstitutionalBonusBudget extends Model
{
    protected $connection = 'sqlsrv';
    protected $table = 'institutional_bonus_budgets';
    protected $guarded = ['id'];
    protected $casts = ['approved_amount_cents' => 'integer', 'committed_amount_cents' => 'integer',
        'consumed_amount_cents' => 'integer', 'version' => 'integer', 'valid_from' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime'];
}
