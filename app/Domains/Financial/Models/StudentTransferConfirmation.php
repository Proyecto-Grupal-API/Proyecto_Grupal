<?php
namespace App\Domains\Financial\Models;
use Illuminate\Database\Eloquent\Model;
class StudentTransferConfirmation extends Model
{
    protected $connection = 'sqlsrv';
    protected $table = 'student_transfer_confirmations';
    protected $guarded = ['id'];
    protected $casts = ['amount_cents' => 'integer', 'duration_seconds' => 'integer', 'recipient_snapshot' => 'array',
        'policy_snapshot' => 'array', 'expires_at' => 'datetime', 'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime'];
}
