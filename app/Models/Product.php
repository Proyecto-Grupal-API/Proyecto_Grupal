<?php
namespace App\Models;
class Product extends Team4Document
{
    protected $collection = 'products';
    protected $fillable = ['business_id','sku','name','description','category','stock_min','stock_max','active'];
    protected $casts = ['active'=>'boolean','stock_min'=>'integer','stock_max'=>'integer'];
}
