<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CsrfBypassForWebhook
{
    public function handle(Request $request, Closure $next)
    {
        // Si la petición es para el webhook del Eq 7, desactivamos el CSRF
        if ($request->is('api/v1/integrations/eq7-events')) {
            // Agregar el token CSRF a la petición para que pase la verificación
            $request->headers->set('X-CSRF-TOKEN', csrf_token());
            // O simplemente marcar la petición como "sin CSRF"
            $request->attributes->set('_skip_csrf', true);
        }

        return $next($request);
    }
}