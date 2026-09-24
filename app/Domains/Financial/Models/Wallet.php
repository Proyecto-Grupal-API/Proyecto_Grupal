<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Enums\WalletType;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    protected $connection = 'pgsql';

    protected $table = 'wallets';

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