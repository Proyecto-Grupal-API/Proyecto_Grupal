<?php

namespace App\Listeners;

use App\Services\IdentityService;
use Illuminate\Auth\Events\Logout;

/**
 * Modulo 1.7 - Cuando el propio estudiante cierra sesion desde este
 * navegador, marcamos como revocado el documento "sessions" (Mongo)
 * que representa esa sesion, para que quede reflejado en el listado
 * de "Dispositivos y sesiones" y en la bitacora de seguridad.
 */
class RevokeSessionOnLogout
{
    public function __construct(private IdentityService $identity)
    {
    }

    public function handle(Logout $event): void
    {
        $sessionId = session('cd_session_id');

        if ($event->user && $sessionId) {
            $this->identity->revokeSessionById($event->user, $sessionId, 'logout');
        }
    }
}
