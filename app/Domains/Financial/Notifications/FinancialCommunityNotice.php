<?php

namespace App\Domains\Financial\Notifications;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class FinancialCommunityNotice extends Model
{
    protected $connection = 'sqlsrv';
    protected $guarded = ['id'];
    protected $casts = ['payload' => 'array', 'attempts' => 'integer'];

    protected static function booted(): void
    {
        static::updating(function (self $notice) {
            if ($notice->isDirty(['tipo', 'operation_id', 'idempotency_key', 'payload'])) {
                throw new LogicException('El contenido del aviso financiero es inmutable.');
            }
        });
    }
}
