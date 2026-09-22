<?php
namespace App\Models;

class PurchaseOrder extends Team4Document
{
    protected $collection = 'purchase_orders';
    protected $fillable = [
        'business_id',
        'supplier_id',
        'folio',
        'status',
        'requested_by',
        'authorized_by',
        'expected_at',
        'notes',
        'total_estimated',
        'items_count',
    ];
    protected $casts = [
        'expected_at' => 'datetime',
        'total_estimated' => 'float',
        'items_count' => 'integer',
    ];
}
