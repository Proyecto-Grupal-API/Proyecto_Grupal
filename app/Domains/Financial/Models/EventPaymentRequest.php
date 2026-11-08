<?php

namespace App\Domains\Financial\Models;

use Illuminate\Database\Eloquent\Model;

class EventPaymentRequest extends Model
{
    protected $connection = 'sqlsrv';

    protected $guarded = ['id'];

    protected $casts = ['amount_cents' => 'integer'];
}
