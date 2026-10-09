<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\CashRegisterStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashRegister extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'cash_registers';

    protected $fillable = [
        'public_id',
        'name',
        'currency',
        'status', 'association_id', 'created_by',
    ];

    protected $casts = [
        'status' => CashRegisterStatus::class,
    ];

    public function shifts(): HasMany
    {
        return $this->hasMany(CashShift::class);
    }
}
