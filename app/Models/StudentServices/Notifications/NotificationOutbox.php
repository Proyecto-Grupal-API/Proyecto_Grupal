<?php
namespace App\Models\StudentServices\Notifications;

use MongoDB\Laravel\Eloquent\Model;

class NotificationOutbox extends Model
{
    protected $connection = 'mongodb';
    protected $table = 'service_notification_outbox';
    protected $fillable = ['key', 'source_class', 'source_id', 'payload', 'status', 'attempts', 'next_attempt_at', 'receipt', 'last_error', 'withdraw_pending'];
    protected function casts(): array
    {
        return ['next_attempt_at' => 'datetime', 'attempts' => 'integer', 'withdraw_pending' => 'boolean'];
    }
}
