<?php

namespace App\Domains\Financial\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WalletHoldPolicy extends Model
{
    protected $connection = 'sqlsrv';
    protected $table = 'wallet_hold_policies';
    protected $fillable = ['public_id', 'operation_type', 'max_duration_seconds', 'active', 'version', 'created_by', 'updated_by'];
    protected $casts = ['max_duration_seconds' => 'integer', 'active' => 'boolean', 'version' => 'integer'];

    public function changes(): HasMany
    {
        return $this->hasMany(WalletHoldPolicyChange::class, 'policy_id', 'public_id');
    }
}
