<?php
namespace App\Models;
class CustomerReturn extends Team4Document
{
    protected $collection = 'customer_returns';
    protected $fillable = ['business_id','folio','customer_id','customer_type','status','reason','sale_reference','resolution','created_by'];
}
