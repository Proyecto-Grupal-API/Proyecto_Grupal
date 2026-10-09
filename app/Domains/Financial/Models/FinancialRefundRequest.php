<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\RefundRequestStatus;
use Illuminate\Database\Eloquent\Model;

class FinancialRefundRequest extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'financial_refund_requests';

    protected $fillable = [
        'public_id',
        'request_transaction_id',
        'original_transaction_id',
        'wallet_id',
        'amount_cents',
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
        'amount_cents' => 'integer',
        'status' => RefundRequestStatus::class,
        'reviewed_at' => 'datetime',
        'completed_at' => 'datetime',
     ];

    public function requestTransaction()
    {
        return $this->belongsTo(
            FinancialTransaction::class,
            'request_transaction_id',
            'public_id'
        );
    }

    public function originalTransaction()
    {
        return $this->belongsTo(
            FinancialTransaction::class,
            'original_transaction_id',
            'public_id'
        );
    }

    public function wallet()
    {
        return $this->belongsTo(
            Wallet::class,
            'wallet_id',
            'public_id'
        );
    }

    public function completedTransaction()
    {
        return $this->belongsTo(
            FinancialTransaction::class,
            'financial_transaction_id',
            'public_id'
        );
    }
}