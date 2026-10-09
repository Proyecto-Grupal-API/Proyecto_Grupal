<?php
namespace App\Domains\Financial\Models;
use Illuminate\Database\Eloquent\Model;
class CashOperationConfirmation extends Model
{
    protected $connection = 'sqlsrv';
    protected $table = 'cash_operation_confirmations';
    protected $fillable = ['public_id', 'cash_shift_id', 'wallet_id', 'operator_id', 'student_id', 'operation', 'amount_cents', 'currency',
        'reason', 'status', 'request_key', 'request_hash', 'expires_at', 'confirmed_at', 'confirmed_by', 'confirmation_ip',
        'rejected_at', 'cancelled_at', 'settlement_key', 'cash_movement_id', 'consumed_at', 'supervisor_required', 'approval_policy_snapshot', 'supervisor_status', 'supervisor_id', 'supervisor_reason', 'supervisor_reviewed_at'];
    protected $casts = ['cash_shift_id' => 'integer', 'amount_cents' => 'integer', 'expires_at' => 'datetime', 'confirmed_at' => 'datetime',
        'rejected_at' => 'datetime', 'cancelled_at' => 'datetime', 'consumed_at' => 'datetime', 'supervisor_required' => 'boolean', 'approval_policy_snapshot' => 'array', 'supervisor_reviewed_at' => 'datetime'];
    public function shift() { return $this->belongsTo(CashShift::class, 'cash_shift_id'); }
    public function wallet() { return $this->belongsTo(Wallet::class, 'wallet_id', 'public_id'); }
}
