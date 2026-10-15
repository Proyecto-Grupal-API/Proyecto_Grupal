<?php
namespace App\Models\InventoryModule;
class Location extends Team4Document
{
    protected $collection = 'locations';
    protected $fillable = ['business_id','warehouse_id','code','name','type','capacity','active'];
    protected $casts = ['capacity'=>'integer','active'=>'boolean'];
}
