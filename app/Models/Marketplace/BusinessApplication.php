<?php

namespace App\Models\Marketplace;

use MongoDB\Laravel\Eloquent\Model;

class BusinessApplication extends Model
{
    protected $casts = ['submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];

    protected $connection = 'marketplace';

    protected $table = 'marketplace_business_applications';

    protected $fillable = ['organization_id', 'integration_error',
        'business_name', 'type', 'applicant_id', 'description', 'status', 'observations',
        'submitted_at', 'reviewed_at', 'reviewed_by', 'business_id',
    ];
}
