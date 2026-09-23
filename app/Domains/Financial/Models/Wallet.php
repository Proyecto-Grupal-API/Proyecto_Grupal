<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Enums\WalletType;
use MongoDB\Laravel\Eloquent\Model;

class Wallet extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'wallets';

    protected $fillable = [
        'public_id',
        'owner_type',
        'owner_id',
        'type',
        'currency',
        'status',
        'available_balance_cents',
        'held_balance_cents',
    ];

    protected $casts = [
        'type' => WalletType::class,
        'status' => WalletStatus::class,
        'available_balance_cents' => 'integer',
        'held_balance_cents' => 'integer',
    ];
}