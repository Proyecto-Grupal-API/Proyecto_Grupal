<?php

namespace App\Http\Middleware;

use App\Services\IdentityService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Modulo 1.7 - En cada peticion web de un usuario autenticado:
 *   1. Asegura una cookie opaca de larga duracion (cd_device_id) que
 *      identifica al navegador de forma estable, sin depender de la
 *      IP (que en redes moviles cambia todo el tiempo y generaria
 *      "dispositivos nuevos" falsos).
 *   2. Resuelve/crea el Device (huella logica del navegador).
 *   3. Resuelve/crea la UserSession ligada a ese navegador.
 *   4. Si es la primera vez que se ve el dispositivo, registra un
 *      security_event "new_device" (alerta de acceso).
 */
class TrackDeviceSession
{
    public function __construct(private IdentityService $identity)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $deviceCookie = $request->cookie('cd_device_id');

        if (! $deviceCookie) {
            $deviceCookie = (string) Str::uuid();

            // 2 años, httpOnly, SameSite=Lax: no es legible por JS ni se
            // envia en navegaciones cross-site, solo identifica al
            // navegador ante nuestro propio backend.
            Cookie::queue('cd_device_id', $deviceCookie, 60 * 24 * 365 * 2, null, null, null, true, false, 'lax');
        }

        if ($request->user()) {
            $this->identity->trackDeviceSession($request, $deviceCookie);
        }

        return $next($request);
    }
}
