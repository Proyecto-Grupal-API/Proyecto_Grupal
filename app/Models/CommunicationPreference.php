<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class CommunicationPreference extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'communication_preferences';
    protected $fillable = ['user_id', 'email', 'push', 'sms', 'updated_by'];
    protected $casts = ['email' => 'boolean', 'push' => 'boolean', 'sms' => 'boolean'];

    public function user() { return $this->belongsTo(User::class, 'user_id'); }
    public function updatedBy() { return $this->belongsTo(User::class, 'updated_by'); }
}
