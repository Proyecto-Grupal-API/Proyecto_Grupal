<?php

namespace App\Services;

use App\Models\PermisoGestionOrganizaciones;
use App\Models\User;

class AutoridadOrganizaciones
{
    // Adaptador local a sustituir por el contrato de permisos contextuales del equipo 1.
    public function permite(?User $user): bool
    {
        return $user && User::where('_id', (string) $user->id)->exists()
            && PermisoGestionOrganizaciones::where('usuario_id', (string) $user->id)->where('activo', true)->exists();
    }
}
