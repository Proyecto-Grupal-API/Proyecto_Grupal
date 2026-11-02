<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

/** Immutable-by-service operation receipt. Contains no OAuth credentials or human actor assertion. */
class BusinessOwnerProvision extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'business_owner_provisions';

    protected $fillable = ['operation_id', 'business_id', 'subject_id', 'client_id', 'result'];
}
