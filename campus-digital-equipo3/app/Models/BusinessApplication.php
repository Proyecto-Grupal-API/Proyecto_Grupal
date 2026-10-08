<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class BusinessApplication extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'business_applications';

    protected $fillable = [
        'business_name','type','applicant_id','description','status','observations',
        'submitted_at','reviewed_at','reviewed_by'
    ];
}
