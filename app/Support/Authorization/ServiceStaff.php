<?php

namespace App\Support\Authorization;

use App\Models\Role;
use App\Models\User;

/**
 * Cargo administrativo de un servicio del campus, sin exigir el administrador global.
 *
 * Identidad (módulo 1.3) asigna roles por contexto; el contexto `service` identifica un servicio. Una cuenta
 * administra un servicio si Identidad le asignó el rol `admin` en ese contexto exacto, o si es administrador global.
 * La comprobación es la misma que usa el resto del sistema (`User::hasRole`, tupla exacta y asignación vigente): no
 * añade excepciones ni relaja ninguna política, y el cargo de un servicio no vale para otro.
 */
final class ServiceStaff
{
    public static function manages(?User $user, string $service): bool
    {
        return $user !== null && $service !== ''
            && ($user->hasRole(Role::ADMIN) || $user->hasRole(Role::ADMIN, 'service', $service));
    }
}
