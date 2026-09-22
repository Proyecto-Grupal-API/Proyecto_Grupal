<?php
namespace App\Models;

class StockCount extends Team4Document
{
    protected $collection = 'stock_counts';
    protected $fillable = [
        'business_id',
        'folio',
        'warehouse_id',
        'location_id',
        'status',
        'started_by',
        'closed_by',
        'notes',
        'differences_count',
    ];
    protected $casts = [
        'differences_count' => 'integer',
    ];
}
