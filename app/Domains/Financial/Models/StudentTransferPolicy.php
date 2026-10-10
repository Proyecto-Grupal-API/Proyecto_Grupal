<?php
namespace App\Domains\Financial\Models;
use Illuminate\Database\Eloquent\Model;
class StudentTransferPolicy extends Model
{
    protected $connection = 'sqlsrv';
    protected $table = 'student_transfer_policies';
    protected $guarded = ['id'];
    protected $casts = ['enabled' => 'boolean', 'minimum_cents' => 'integer', 'maximum_cents' => 'integer', 'daily_cents' => 'integer', 'monthly_cents' => 'integer', 'version' => 'integer'];
}
