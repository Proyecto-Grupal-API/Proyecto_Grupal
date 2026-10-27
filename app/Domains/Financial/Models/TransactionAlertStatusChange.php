<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\AlertStatus;
use Illuminate\Database\Eloquent\Model;

class TransactionAlertStatusChange extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'transaction_alert_status_changes';

    protected $fillable = [
        'public_id',
        'alert_id',
        'from_status',
        'to_status',
        'actor_id',
        'note',
    ];

    protected $casts = [
        'from_status' => AlertStatus::class,
        'to_status' => AlertStatus::class,
    ];
}
