<?php
namespace App\Domains\Financial\Models;
use Illuminate\Database\Eloquent\Model;
class CashApprovalPolicy extends Model {
    protected $connection = 'sqlsrv';
    protected $table = 'cash_approval_policies';
    protected $fillable = ['association_id', 'operation', 'currency', 'enabled', 'threshold_cents', 'version'];
    protected $casts = ['enabled' => 'boolean', 'threshold_cents' => 'integer', 'version' => 'integer'];
}
