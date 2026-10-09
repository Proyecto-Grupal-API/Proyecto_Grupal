<?php
namespace App\Domains\Financial\Models;
use Illuminate\Database\Eloquent\Model;
class CashApprovalPolicyChange extends Model {
    protected $connection = 'sqlsrv';
    protected $table = 'cash_approval_policy_changes';
    public $timestamps = false;
    protected $fillable = ['cash_approval_policy_id', 'idempotency_key', 'request_hash', 'actor_id', 'reason', 'before_snapshot', 'after_snapshot', 'created_at'];
    protected $casts = ['before_snapshot' => 'array', 'after_snapshot' => 'array', 'created_at' => 'datetime'];
}
