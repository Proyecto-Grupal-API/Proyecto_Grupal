<?php
namespace App\Models;

class Supplier extends Team4Document
{
    protected $collection = 'suppliers';
    protected $fillable = [
        'business_id',
        'code',
        'legal_name',
        'trade_name',
        'tax_id',
        'contact_name',
        'contact_email',
        'contact_phone',
        'payment_terms',
        'notes',
        'status',
    ];
}
