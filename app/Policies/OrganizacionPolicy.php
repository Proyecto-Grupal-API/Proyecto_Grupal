<?php

namespace App\Policies;

use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\RolOrganizacion;
use App\Models\User;
use App\Services\AutoridadOrganizaciones;
use App\Services\ComiteBecas;
use App\Services\PermisosComunidad;

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

    public function comunicar(User $user, Organizacion $organizacion): bool
    {
        return app(PermisosComunidad::class)->permite($user, $organizacion, 'comunicacion.gestionar');
    }

    public function gestionarBecas(User $user, Organizacion $organizacion): bool
    {
        return app(PermisosComunidad::class)->permite($user, $organizacion, 'becas.gestionar');
    }

    public function revisarBecas(User $user, Organizacion $organizacion): bool
    {
        return app(PermisosComunidad::class)->permite($user, $organizacion, 'becas.revisar');
    }

    public function verGestionBecas(User $user, Organizacion $organizacion): bool
    {
        return $this->gestionarBecas($user, $organizacion) || $this->revisarBecas($user, $organizacion) || app(ComiteBecas::class)->participa($user, $organizacion);
    }
}
