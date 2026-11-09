<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;

class StudentProfilePolicy
{
    public function viewAny(User $user): bool
    {
        // Antes se comprobaba también un rol 'student_manager' que
        // nunca existía en el catálogo (Role::VALID_ROLES), por lo que
        // en la práctica esta comprobación equivalía silenciosamente a
        // "solo admin". Se reemplaza por 'maestro', que sí es un rol
        // real del catálogo con responsabilidad sobre estudiantes.
        foreach (Role::STUDENT_MANAGEMENT_ROLES as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, StudentProfile $profile): bool
    {
        return $this->viewAny($user);
    }

    public function viewServices(User $user, StudentProfile $profile): bool
    {
        return (string) $user->getKey() === (string) $profile->user_id || $this->viewAny($user);
    }

    public function updateAcademicStatus(User $user, StudentProfile $profile): bool
    {
        return $this->viewAny($user);
    }
}
