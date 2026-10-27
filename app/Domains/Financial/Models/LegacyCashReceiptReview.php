<?php

namespace App\Domains\Financial\Models;

use Illuminate\Database\Eloquent\Model;

class LegacyCashReceiptReview extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'legacy_cash_receipt_reviews';

    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'reviewed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('La revisión histórica es inmutable.'));
        static::deleting(fn () => throw new \LogicException('La revisión histórica conserva su auditoría.'));
    }
}
