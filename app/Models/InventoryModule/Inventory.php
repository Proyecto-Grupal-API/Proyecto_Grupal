<?php
namespace App\Models\InventoryModule;
class Inventory extends Team4Document
{
    protected $collection = 'inventories';
    protected $fillable = ['business_id','product_id','variant_id','location_id','on_hand','reserved','available','status'];
    protected $casts = ['on_hand'=>'integer','reserved'=>'integer','available'=>'integer'];
}
