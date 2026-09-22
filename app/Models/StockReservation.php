<?php
namespace App\Models;
class StockReservation extends Team4Document
{
    protected $collection = 'stock_reservations';
    protected $fillable = ['business_id','product_id','variant_id','location_id','quantity','status','source','external_reference','expires_at'];
    protected $casts = ['quantity'=>'integer','expires_at'=>'datetime'];
}
