<?php
namespace App\Models;
class StockMovement extends Team4Document
{
    protected $collection = 'stock_movements';
    protected $fillable = ['business_id','product_id','variant_id','location_id','type','quantity','reason','external_reference','actor_id','correlation_id'];
    protected $casts = ['quantity'=>'integer'];
}
