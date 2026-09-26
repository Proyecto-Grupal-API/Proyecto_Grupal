<?php

namespace App\Policies;

use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\RolOrganizacion;
use App\Models\User;
use App\Services\AutoridadOrganizaciones;

class OrganizacionPolicy
{
    public function gestionarRegistro(User $user): bool
    {
        return app(AutoridadOrganizaciones::class)->permite($user);
    }

    public function view(User $user, Organizacion $organizacion): bool
    {
        return Organizacion::where('_id', (string) $organizacion->id)->where('estado', 'activa')->exists() && MiembroOrganizacion::activos()
            ->where('organizacion_id', (string) $organizacion->id)->where('usuario_id', (string) $user->id)->exists();
    }

    public function update(User $user, Organizacion $organizacion): bool
    {
        return $this->view($user, $organizacion) && RolOrganizacion::vigentes()
            ->where('organizacion_id', (string) $organizacion->id)->where('usuario_id', (string) $user->id)
            ->where('slug_rol', 'presidencia')->exists();
    }
}
