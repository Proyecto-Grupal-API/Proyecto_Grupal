<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

/** Initial ownership reservation only; never an authorization source or a transfer mechanism. */
class BusinessOwnerClaim extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'business_owner_claims';

    protected $fillable = ['business_id', 'subject_id'];
}
