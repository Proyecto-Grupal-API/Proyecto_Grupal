<?php

namespace App\Domains\Financial\Models;

use Illuminate\Database\Eloquent\Model;

class PurchasePaymentBonus extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'purchase_payment_bonuses';

    protected $fillable = [
        'purchase_payment_id',
        'bonus_id',
        'amount_cents',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
    ];
}