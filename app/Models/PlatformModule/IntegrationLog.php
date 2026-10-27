<?php

namespace App\Models\PlatformModule;

use MongoDB\Laravel\Eloquent\Model;

class IntegrationLog extends Model
{
    protected $connection = 'platform';

    protected $collection = 'integration_logs';

    public $timestamps = true;

    protected $fillable = [
        'domain',
        'direction',
        'endpoint',
        'status',
        'attempt',
        'request_payload',
        'response_payload',
        'error_message',
        'correlation_id',
    ];

    protected $casts = [
        'attempt' => 'integer',
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];
}
