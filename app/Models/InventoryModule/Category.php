<?php

namespace App\Models\InventoryModule;

class Category extends Team4Document
{
    protected $collection = 'categories';

    protected $fillable = ['business_id', 'slug', 'name', 'description', 'active', 'has_sizes', 'has_colors', 'has_seasons'];

    protected $casts = ['active' => 'boolean', 'has_sizes' => 'boolean', 'has_colors' => 'boolean', 'has_seasons' => 'boolean'];
}
