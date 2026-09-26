<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\WithdrawalMethod;
use App\Domains\Financial\Enums\WithdrawalStatus;
use Illuminate\Database\Eloquent\Model;

class Withdrawal extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'withdrawals';

    protected $fillable = [
        'public_id',
        'folio',
        'wallet_id',
        'amount_cents',
        'currency',
        'method',
        'status',
        'agent_id',
        'cash_shift_id',
        'external_reference',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'method' => WithdrawalMethod::class,
        'status' => WithdrawalStatus::class,
    ];
}