<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\MovementType;
use MongoDB\Laravel\Eloquent\Model;

class LedgerEntry extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'ledger_entries';

    protected $fillable = [
        'public_id',
        'transaction_id',
        'wallet_id',
        'movement_type',
        'amount_cents',
        'balance_after_cents',
    ];

    protected $casts = [
        'movement_type' => MovementType::class,
        'amount_cents' => 'integer',
        'balance_after_cents' => 'integer',
    ];
}