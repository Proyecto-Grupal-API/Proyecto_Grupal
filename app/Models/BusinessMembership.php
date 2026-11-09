<?php

namespace App\Models;

class BusinessMembership extends AuthorizationLifecycleRecord
{
    protected $collection = 'business_memberships';

    protected $fillable = ['user_id', 'business_id', 'status', 'starts_at', 'ends_at', 'joined_at', 'assigned_by', 'reason'];

    protected function identityFields(): array
    {
        return ['user_id', 'business_id'];
    }

    protected function validateContext(): void
    {
        $this->business_id = self::normalizeIdentifier($this->business_id, 'business_id');
        $this->joined_at ??= now();
    }
}
