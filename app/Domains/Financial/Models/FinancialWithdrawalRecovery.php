<?php

namespace App\Domains\Financial\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialWithdrawalRecovery extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'financial_withdrawal_recoveries';

    protected $fillable = [
        'public_id',
        'refund_request_id',
        'withdrawal_id',
        'amount_cents',
        'currency',
        'recovery_reference',
        'confirmed_by',
        'confirmed_at',
        'notes', 'cash_movement_id', 'cash_shift_id',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'confirmed_at' => 'datetime',
    ];

    public function cashMovement()
    {
        return $this->belongsTo(CashMovement::class, 'cash_movement_id', 'public_id');
    }

    public function refundRequest()
    {
        return $this->belongsTo(
            FinancialRefundRequest::class,
            'refund_request_id',
            'public_id'
        );
    }

    public function withdrawal()
    {
        return $this->belongsTo(
            Withdrawal::class,
            'withdrawal_id',
            'public_id'
        );
    }
}