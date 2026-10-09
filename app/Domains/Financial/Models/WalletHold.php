<?php
namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\WalletHoldStatus;
use Illuminate\Database\Eloquent\Model;

class WalletHold extends Model
{
    protected $connection = 'sqlsrv';
    protected $table = 'wallet_holds';
    protected $fillable = [
        'public_id', 'wallet_id', 'amount_cents', 'currency', 'operation_type',
        'reference_type', 'reference_id', 'status', 'expires_at', 'requested_by',
        'reason', 'hold_transaction_id', 'closing_transaction_id', 'closed_by',
        'closing_reason', 'closed_at',
    ];
    protected $casts = [
        'amount_cents' => 'integer', 'status' => WalletHoldStatus::class,
        'expires_at' => 'datetime', 'closed_at' => 'datetime',
    ];
}
