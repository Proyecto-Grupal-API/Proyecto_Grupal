<?php
namespace App\Models;
class SupplierReturn extends Team4Document
{
    protected $collection = 'supplier_returns';
    protected $fillable = ['business_id','folio','supplier_id','status','reason','source_type','source_reference','created_by'];
}
