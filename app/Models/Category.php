<?php
namespace App\Models;

class Category extends Team4Document
{
    protected $collection = 'categories';
    protected $fillable = ['business_id', 'slug',
        'has_sizes',
        'has_colors',
        'has_seasons', 'name', 'description', 'active'];
    protected $casts = ['active' => 'boolean'];
}
