<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Para las peticiones de fondo de la barra común (latido de seguridad y notificaciones).
 *
 * Comparten la sesión con la página y se disparan cada pocos segundos. Sin este cuidado, una de ellas podía gastar el
 * aviso de confirmación destinado a la página siguiente (el aviso vive en la sesión sólo hasta la petición siguiente), o
 * guardar encima de la sesión que una operación acababa de escribir mientras la petición de fondo seguía en curso.
 *
 * Al terminar la petición de fondo se vuelve a leer la sesión guardada y se conservan los avisos pendientes: lo que
 * escribió la operación llega intacto a la página siguiente, que es quien debe mostrarlo y gastarlo.
 */
class PreserveSessionForBackgroundRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->hasSession()) {
            $session = $request->session();
            $session->start();     // relee lo que otras peticiones de la misma sesión hayan guardado entretanto
            $session->reflash();   // los avisos pendientes siguen pendientes para la página siguiente
        }

        return $response;
    }
}
