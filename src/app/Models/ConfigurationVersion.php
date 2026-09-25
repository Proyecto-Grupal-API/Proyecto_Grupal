<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class ConfigurationVersion extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'configuration_versions';

    protected $fillable = [
        'config_key',
        'version',
        'value',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
        'rejected_reason',
        'activated_at',
        'superseded_at',
        'superseded_by_version',
    ];

    protected $casts = [
        'value' => 'array',
        'approved_by' => 'array',
        'version' => 'integer',
        'approved_at' => 'datetime',
        'activated_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public const TRANSITIONS = [
        'draft'            => ['pending_approval'],
        'pending_approval' => ['approved', 'rejected'],
        'approved'         => ['active'],
        'rejected'         => [],
        'active'           => ['superseded'],
        'superseded'       => [],
    ];

    public function canTransitionTo(string $next): bool
    {
        return in_array($next, self::TRANSITIONS[$this->status] ?? [], true);
    }

    protected static function booted()
    {
        static::updating(function (self $model) {
            if (in_array($model->getOriginal('status'), ['approved', 'active'], true)
                && $model->isDirty('value')) {
                throw new \RuntimeException('No se puede modificar el valor de una version aprobada/activa. Crea una nueva version.');
            }
        });
    }
}
