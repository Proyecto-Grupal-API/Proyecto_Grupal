<?php

namespace App\Services;

use App\Models\User;
use Closure;

class ConditionalPasswordUpdater
{
    /** Replace a credential only while the exact observed version is still current. */
    public function replace(User $user, ?string $observedHash, array $changes, ?Closure $eligibility = null): bool
    {
        $query = User::query()->whereKey($user->getKey())->whereNull('deleted_at');

        if ($observedHash === null) {
            $query->whereNull('password');
        } else {
            $query->where('password', $observedHash);
        }

        if ($eligibility !== null) {
            $eligibility($query);
        }

        return $query->update($changes) === 1;
    }
}
