<?php

namespace App\Domains\Financial\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashReceipt extends Model
{
    protected $connection = 'sqlsrv';
    protected $table = 'cash_receipts';
    protected $fillable = ['public_id', 'cash_movement_id', 'folio', 'snapshot', 'issued_at'];
    protected $casts = ['cash_movement_id' => 'integer', 'snapshot' => 'array', 'issued_at' => 'datetime'];

    public function movement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class, 'cash_movement_id');
    }
}
