<?php

namespace App\Policies;

use App\Models\NfcCard;
use App\Models\Role;
use App\Models\User;

class NfcCardPolicy
{
    /**
     * Ver el listado completo de tarjetas NFC de todo el campus.
     * Un estudiante normal NO debe ver las tarjetas de los demás.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::ADMIN);
    }

    /**
     * Ver el detalle/historial de una tarjeta puntual: el dueño de la
     * tarjeta puede consultar la suya; un admin puede consultar
     * cualquiera.
     */
    public function view(User $user, NfcCard $card): bool
    {
        return $user->hasRole(Role::ADMIN) || (string) $card->user_id === (string) $user->getKey();
    }

    /**
     * Registrar (dar de alta) una tarjeta NFC para un estudiante.
     * Operación sensible de identidad: solo administración.
     */
    public function create(User $user): bool
    {
        return $user->hasRole(Role::ADMIN);
    }

    /**
     * Cambiar el estado de una tarjeta (bloquear, suspender, reemplazar).
     * Ciclo de vida de credenciales: solo administración.
     */
    public function updateStatus(User $user, NfcCard $card): bool
    {
        return $user->hasRole(Role::ADMIN);
    }
}
