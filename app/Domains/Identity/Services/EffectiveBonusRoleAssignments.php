<?php
namespace App\Domains\Identity\Services;
use App\Models\RoleAssignment;
use App\Models\User;
final class EffectiveBonusRoleAssignments
{
    public function queryForRoles(User $user, array $roles)
    {
        return $this->activeCurrent(RoleAssignment::where('user_id', (string)$user->getKey())->whereIn('role_key', $roles));
    }
    public function activeCurrent($query)
    {
        $now = now();
        return $query->where('status', 'active')->where('is_current', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }
}
