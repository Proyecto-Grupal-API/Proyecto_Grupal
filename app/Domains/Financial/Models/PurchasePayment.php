<?php

namespace App\Domains\Financial\Models;

use Illuminate\Database\Eloquent\Model;

class PurchasePayment extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'purchase_payments';

   protected $fillable = [
    'public_id',
    'idempotency_key',
    'wallet_id',
    'bonus_id',
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
    ];
}