<?php
namespace App\Domains\Financial\Models;
use Illuminate\Database\Eloquent\Model;
class StudentTransferPolicyChange extends Model
{
    protected $connection = 'sqlsrv';
    protected $table = 'student_transfer_policy_changes';
    protected $guarded = ['id'];
    protected $casts = ['version' => 'integer', 'before_data' => 'array', 'after_data' => 'array', 'audit_context' => 'array'];
}
