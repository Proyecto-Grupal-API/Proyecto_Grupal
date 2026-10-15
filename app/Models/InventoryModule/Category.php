<?php
namespace App\Models\InventoryModule;

class Category extends Team4Document
{
    protected $collection = 'categories';
    protected $fillable = ['business_id', 'slug', 'name', 'description', 'active'];
    protected $casts = ['active' => 'boolean'];
}
