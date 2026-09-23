<?php

namespace App\Policies;

use App\Models\Evento;
use App\Models\Organizacion;
use App\Models\StaffEvento;
use App\Models\User;

class EventoPolicy
{
    public function validarAcceso(User $user, Evento $evento): bool
    {
        $org = Organizacion::find($evento->organizacion_id);
        if (! $org || ! User::find($user->id) || ! (new OrganizacionPolicy)->view($user, $org)) {
            return false;
        }

        return (new OrganizacionPolicy)->update($user, $org) || StaffEvento::where('evento_id', (string) $evento->id)
            ->where('organizacion_id', (string) $org->id)->where('usuario_id', (string) $user->id)->whereNull('eliminado_en')->exists();
    }
}
