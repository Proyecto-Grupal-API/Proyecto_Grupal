<?php
namespace App\Models\InventoryModule;
class InventoryBusiness extends Team4Document
{
    protected $collection = 'inventory_businesses';
    protected $fillable = ['business_id', 'name', 'active'];
    protected $casts = ['active' => 'boolean'];
}
