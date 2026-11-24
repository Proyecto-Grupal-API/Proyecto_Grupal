<?php

namespace App\Support\Authorization;

use App\Models\Role;
use App\Models\User;

/**
 * Coordinación de comunicaciones (aviso general a todos los usuarios): cargo exacto que asigna Identidad, el rol `admin`
 * en el contexto service:<comunidad.avisos_generales.servicio>. Es explícito: ni el administrador global ni un permiso
 * de organización lo conceden.
 */
final class CoordinadorComunicaciones
{
    public static function permite(?User $user): bool
    {
        $service = (string) config('comunidad.avisos_generales.servicio', '');

        return $user !== null && $service !== '' && $user->hasRole(Role::ADMIN, 'service', $service);
    }
}
