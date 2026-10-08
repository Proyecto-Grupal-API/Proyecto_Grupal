<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class BusinessMember extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'business_members';

    protected $fillable = ['business_id','user_id','role','status','scopes'];
    protected $casts = ['scopes' => 'array'];
}
