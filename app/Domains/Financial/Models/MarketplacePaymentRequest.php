<?php
namespace App\Domains\Financial\Models;
use Illuminate\Database\Eloquent\Model;
class MarketplacePaymentRequest extends Model {
    protected $connection='sqlsrv';protected $table='marketplace_payment_requests';protected $guarded=['id'];
    protected $casts=['payload'=>'array','amount_cents'=>'integer','expires_at'=>'datetime','confirmed_at'=>'datetime','audit_context'=>'array'];
}
