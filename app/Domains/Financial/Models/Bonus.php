<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\BonusStatus;
use App\Domains\Financial\Enums\BonusType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bonus extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'bonuses';

    protected $fillable = [
        'public_id',
        'beneficiary_type',
        'beneficiary_id',
        'issuer_type',
        'issuer_id',
        'type',
        'original_amount_cents',
        'remaining_amount_cents',
        'currency',
        'status',
        'valid_from',
        'expires_at',
        'combinable',
        'allows_partial_use',
        'external_reference',
    ];

    protected $casts = [
        'type' => BonusType::class,
        'status' => BonusStatus::class,
        'original_amount_cents' => 'integer',
        'remaining_amount_cents' => 'integer',
        'valid_from' => 'datetime',
        'expires_at' => 'datetime',
        'combinable' => 'boolean',
        'allows_partial_use' => 'boolean',
    ];
 
    public function restrictions(): HasMany
{
    return $this->hasMany(
        BonusRestriction::class,
        'bonus_id',
        'public_id'
    );
}

public function ledgerEntries(): HasMany
{
    return $this->hasMany(
        BonusLedgerEntry::class,
        'bonus_id',
        'public_id'
    );
}
}