<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class AuditLog extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'audit_logs';

    public $timestamps = true;

    protected $fillable = [
        'actor_id',
        'actor_role',
        'action',
        'entity_type',
        'entity_id',
        'before',
        'after',
        'ip_address',
        'device',
        'correlation_id',
        'source_domain',
        'reason',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    protected static function booted()
    {
        static::updating(fn () => throw new \RuntimeException('audit_logs es append-only.'));
        static::deleting(fn () => throw new \RuntimeException('audit_logs es append-only.'));
    }

    public function correlation()
    {
        return $this->belongsTo(AuditCorrelation::class, 'correlation_id', 'correlation_id');
    }
}
