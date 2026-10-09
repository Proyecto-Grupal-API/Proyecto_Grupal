<?php

namespace App\Domains\Financial\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashRegisterChange extends Model
{
    protected $connection = 'sqlsrv';
    protected $table = 'cash_register_changes';
    protected $fillable = ['public_id', 'cash_register_id', 'version', 'action', 'before_state', 'after_state',
        'actor_id', 'reason', 'idempotency_key', 'request_hash'];
    protected $casts = ['cash_register_id' => 'integer', 'version' => 'integer', 'before_state' => 'array', 'after_state' => 'array'];

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }
}
