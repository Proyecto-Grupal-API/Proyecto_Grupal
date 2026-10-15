<?php
namespace App\Models\InventoryModule;
class Warehouse extends Team4Document
{
    protected $collection = 'warehouses';
    protected $fillable = ['business_id','code','name','type','active'];
    protected $casts = ['active'=>'boolean'];
}
