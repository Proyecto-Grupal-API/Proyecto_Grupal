<?php

namespace App\Domains\Financial\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRefundBonus extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'purchase_refund_bonuses';

    protected $fillable = [
        'refund_request_id',
        'bonus_id',
        'amount_cents',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
    ];

    public function refundRequest(): BelongsTo
    {
        return $this->belongsTo(
            PurchaseRefundRequest::class,
            'refund_request_id',
            'public_id'
        );
    }

    public function bonus(): BelongsTo
    {
        return $this->belongsTo(
            Bonus::class,
            'bonus_id',
            'public_id'
        );
    }
}