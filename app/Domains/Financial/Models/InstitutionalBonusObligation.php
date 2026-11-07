<?php
namespace App\Domains\Financial\Models;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Outstanding institutional debt, not cash or a merchant wallet credit. Settlement is not implemented. */
final class InstitutionalBonusObligation extends Model
{
    protected $connection = 'sqlsrv';
    protected $table = 'institutional_bonus_obligations';
    protected $guarded = ['id'];
    protected $casts = ['amount_cents' => 'integer', 'refunded_amount_cents' => 'integer'];
    protected static function booted(): void
    {
        static::updating(function ($row) {
            foreach (['public_id','purchase_payment_id','bonus_id','budget_id','operation_id','amount_cents','currency','business_id','payer_reference','status'] as $key) {
                if ($row->isDirty($key)) throw new LogicException('La evidencia original de obligacion es inmutable.');
            }
        });
        static::deleting(function () { throw new LogicException('La obligacion conserva su evidencia.'); });
    }
}
