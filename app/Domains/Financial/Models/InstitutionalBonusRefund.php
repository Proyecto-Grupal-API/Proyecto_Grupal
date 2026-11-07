<?php
namespace App\Domains\Financial\Models;
use Illuminate\Database\Eloquent\Model;
use LogicException;
final class InstitutionalBonusRefund extends Model
{
    protected $connection = 'sqlsrv';
    protected $table = 'institutional_bonus_refunds';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['amount_cents' => 'integer', 'created_at' => 'immutable_datetime'];
    protected static function booted(): void
    {
        static::updating(function () { throw new LogicException('La devolucion institucional es inmutable.'); });
        static::deleting(function () { throw new LogicException('La devolucion institucional es inmutable.'); });
    }
}
