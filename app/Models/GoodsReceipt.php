<?php
namespace App\Models;

class GoodsReceipt extends Team4Document
{
    protected $collection = 'goods_receipts';
    protected $fillable = [
        'business_id',
        'purchase_order_id',
        'folio',
        'received_by',
        'status',
        'notes',
        'received_at',
    ];
    protected $casts = [
        'received_at' => 'datetime',
    ];
}
