<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    /**
     * Ver el catálogo de roles y los roles propios (pantalla /roles).
     * Cualquier usuario autenticado puede consultar SUS PROPIOS roles;
     * la restricción fuerte está en assign(), no aquí.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Único punto de verdad de la regla de negocio "solo un
     * administrador puede asignar roles".
     *
     * Un administrador puede asignar roles a cualquier usuario
     * (incluido él mismo). Nadie más puede asignar roles, ni
     * siquiera a sí mismo: estar autenticado NO es suficiente.
     */
    public function assign(User $user): bool
    {
        return $user->hasRole(Role::ADMIN);
    }
}
