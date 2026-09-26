<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\TopUpMethod;
use App\Domains\Financial\Enums\TopUpStatus;
use Illuminate\Database\Eloquent\Model;

class TopUp extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'topups';

    protected $fillable = [
        'public_id',
        'folio',
        'wallet_id',
        'amount_cents',
        'currency',
        'method',
        'status',
        'agent_id',
        'cash_shift_id',
        'external_reference',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'method' => TopUpMethod::class,
        'status' => TopUpStatus::class,
    ];
}