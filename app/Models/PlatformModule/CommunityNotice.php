<?php

namespace App\Models\PlatformModule;

use LogicException;
use MongoDB\Laravel\Eloquent\Model;

final class CommunityNotice extends Model
{
    public const COLLECTION = 'platform_community_notices';
    public const SCANS = 'platform_community_notice_scans';

    protected $connection = 'platform';
    protected $table = self::COLLECTION;
    protected $guarded = ['id', '_id'];
    protected $casts = ['payload' => 'array', 'attempts' => 'integer',
        'next_attempt_at' => 'datetime', 'locked_until' => 'datetime', 'delivered_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $notice) {
            if ($notice->isDirty(['tipo', 'source_id', 'idempotency_key', 'payload', 'created_at'])) {
                throw new LogicException('El contenido del aviso de recompensas es inmutable.');
            }
        });
    }
}
