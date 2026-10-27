<?php

namespace App\Models\PlatformModule;

use MongoDB\Laravel\Eloquent\Model;

class RewardProgramMembership extends Model
{
    protected $connection = 'platform';

    protected $collection = 'reward_program_memberships';

    protected $fillable = [
        'business_id',
        'business_name',
        'category',
        'contact_email',
        'status',
        'compensation_model',
        'accepted_terms_at',
        'joined_at',
        'deactivated_at',
        'deactivation_reason',
        'metadata',
    ];

    protected $casts = [
        'accepted_terms_at' => 'datetime',
        'joined_at' => 'datetime',
        'deactivated_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function earningRules()
    {
        return $this->hasMany(EarningRule::class, 'business_id', 'business_id');
    }

    public function rewards()
    {
        return $this->hasMany(Reward::class, 'business_id', 'business_id');
    }
}
