<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\BonusRestrictionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BonusRestriction extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'bonus_restrictions';

    protected $fillable = [
        'public_id',
        'bonus_id',
        'restriction_type',
        'target_id',
    ];

    protected $casts = [
        'restriction_type' => BonusRestrictionType::class,
    ];

    public function bonus(): BelongsTo
    {
        return $this->belongsTo(
            Bonus::class,
            'bonus_id',
            'public_id'
        );
    }
}