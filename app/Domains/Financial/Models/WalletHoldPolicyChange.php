<?php

namespace App\Domains\Financial\Models;

use Illuminate\Database\Eloquent\Model;

class WalletHoldPolicyChange extends Model
{
    protected $connection = 'sqlsrv';
    protected $table = 'wallet_hold_policy_changes';
    protected $fillable = ['public_id', 'policy_id', 'version', 'change_type', 'actor_id', 'reason', 'correlation_id', 'before', 'after'];
    protected $casts = ['version' => 'integer', 'before' => 'array', 'after' => 'array'];
}
