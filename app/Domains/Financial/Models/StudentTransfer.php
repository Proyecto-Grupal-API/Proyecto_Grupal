<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\StudentTransferKind;
use Illuminate\Database\Eloquent\Model;

class StudentTransfer extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'student_transfers';

    protected $guarded = ['id'];

    protected $casts = ['kind' => StudentTransferKind::class, 'amount_cents' => 'integer', 'policy_version' => 'integer', 'policy_snapshot' => 'array', 'completed_at' => 'datetime', 'audit_context' => 'array'];
}
