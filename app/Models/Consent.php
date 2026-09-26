<?php

namespace App\Models;

use App\Enums\ConsentType;
use MongoDB\Laravel\Eloquent\Model;

class Consent extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'consents';
    protected $fillable = ['user_id', 'student_profile_id', 'type', 'version', 'status', 'accepted_at', 'revoked_at', 'actor_id', 'revokes_consent_id'];
    protected $casts = ['type' => ConsentType::class, 'accepted_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class, 'user_id'); }
    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
}
