<?php

namespace App\Models\PlatformModule;

use MongoDB\Laravel\Eloquent\Model;

class AuditCorrelation extends Model
{
    protected $connection = 'platform';

    protected $collection = 'audit_correlations';

    protected $fillable = [
        'correlation_id',
        'origin_domain',
        'description',
        'related_entities',
    ];

    protected $casts = [
        'related_entities' => 'array',
    ];

    public function logs()
    {
        return $this->hasMany(AuditLog::class, 'correlation_id', 'correlation_id');
    }
}
