<?php
namespace App\Services\StudentServices;

use App\Models\Role;
use App\Models\User;

class ServiceAuthority
{
    // Team 1 currently publishes no library/operator role. Use its existing administrator authority.
    // Do not infer operator access from student_manager, association positions or legacy arrays.
    public function staff(?User $user): bool
    {
        return $user?->hasRole(Role::ADMIN) ?? false;
    }

    public function propias($query)
    {
        $user = request()->user();
        return $this->staff($user) ? $query : $query->where('student_id', (string) $user?->id);
    }
}
