<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Model;

class FinancialTransaction extends Model
{
    protected $connection = 'pgsql';

    protected $table = 'financial_transactions';

    protected $fillable = [
        'public_id',
        'idempotency_key',
        'status',
        'reference_type',
        'reference_id',
        'original_transaction_id',
        'metadata',
    ];

    protected $casts = [
        'status' => TransactionStatus::class,
        'metadata' => 'array',
    ];
}