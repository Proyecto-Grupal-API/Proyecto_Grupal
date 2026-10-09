<?php
namespace App\Domains\Financial\Models;
use Illuminate\Database\Eloquent\Model;
class CashAdministrativeRequest extends Model {
    protected $connection = 'sqlsrv';
    protected $table = 'cash_administrative_requests';
    protected $fillable = ['public_id', 'cash_shift_id', 'operation', 'amount_cents', 'currency', 'refund_request_id', 'operator_id', 'student_id',
        'reason', 'request_key', 'request_hash', 'supervisor_required', 'approval_policy_snapshot', 'status', 'supervisor_id', 'supervisor_reason',
        'supervisor_reviewed_at', 'expires_at', 'cancelled_at', 'consumed_at', 'settlement_key', 'cash_movement_id'];
    protected $casts = ['cash_shift_id' => 'integer', 'amount_cents' => 'integer', 'supervisor_required' => 'boolean', 'approval_policy_snapshot' => 'array',
        'supervisor_reviewed_at' => 'datetime', 'expires_at' => 'datetime', 'cancelled_at' => 'datetime', 'consumed_at' => 'datetime'];
    public function shift() { return $this->belongsTo(CashShift::class, 'cash_shift_id'); }
    public function refund() { return $this->belongsTo(FinancialRefundRequest::class, 'refund_request_id', 'public_id'); }
}
