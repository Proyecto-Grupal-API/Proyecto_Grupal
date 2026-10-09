<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\RefundRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRefundRequest extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'purchase_refund_requests';

    protected $fillable = [
        'public_id',
        'purchase_payment_id',
        'request_transaction_id',
        'wallet_id',
        'currency',
        'total_amount_cents',
        'wallet_amount_cents',
        'bonus_amount_cents',
        'status',
        'requested_by',
        'reviewed_by',
        'reason',
        'review_reason',
        'reviewed_at',
        'completed_at',
        'financial_transaction_id',
    ];

    protected $casts = [
        'total_amount_cents' => 'integer',
        'wallet_amount_cents' => 'integer',
        'bonus_amount_cents' => 'integer',
        'status' => RefundRequestStatus::class,
        'reviewed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function purchasePayment(): BelongsTo
    {
        return $this->belongsTo(
            PurchasePayment::class,
            'purchase_payment_id',
            'public_id'
        );
    }

    public function bonuses(): HasMany
    {
        return $this->hasMany(
            PurchaseRefundBonus::class,
            'refund_request_id',
            'public_id'
        );
    }

    public function requestTransaction(): BelongsTo
    {
        return $this->belongsTo(
            FinancialTransaction::class,
            'request_transaction_id',
            'public_id'
        );
    }

    public function completedTransaction(): BelongsTo
    {
        return $this->belongsTo(
            FinancialTransaction::class,
            'financial_transaction_id',
            'public_id'
        );
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(
            Wallet::class,
            'wallet_id',
            'public_id'
        );
    }
}