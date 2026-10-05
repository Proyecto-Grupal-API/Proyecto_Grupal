<?php

namespace App\Domains\Financial\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchasePayment extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'purchase_payments';

   protected $fillable = [
    'public_id',
    'idempotency_key',
    'wallet_id',
    'bonus_id',
    'requested_bonus_ids',
    'business_id',
    'category_id',
    'currency',
    'total_amount_cents',
    'bonus_amount_cents',
    'wallet_amount_cents',
    'status',
];

    protected $casts = [
        'total_amount_cents' => 'integer',
        'bonus_amount_cents' => 'integer',
        'wallet_amount_cents' => 'integer',
        'requested_bonus_ids' => 'array',
    ];

    public function bonuses(): HasMany
    {
        return $this->hasMany(
            PurchasePaymentBonus::class,
            'purchase_payment_id',
            'public_id'
        );
    }
}