<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\MovementType;
use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'ledger_entries';

    protected $fillable = [
        'public_id',
        'transaction_id',
        'wallet_id',
        'movement_type',
        'amount_cents',
        'balance_after_cents',
        'available_balance_after_cents',
        'held_balance_after_cents',
    ];

    protected $casts = [
        'movement_type' => MovementType::class,
        'amount_cents' => 'integer',
        'balance_after_cents' => 'integer',
        'available_balance_after_cents' => 'integer',
        'held_balance_after_cents' => 'integer',
    ];
}