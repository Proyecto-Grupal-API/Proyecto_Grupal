<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class QrValidationPolicy
{
    public function validate(User $user): bool
    {
        return $user->hasRole(Role::ADMIN);
    }
}
