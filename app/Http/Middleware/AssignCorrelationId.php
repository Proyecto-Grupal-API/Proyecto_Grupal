<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Toma X-Correlation-Id del cliente (o genera uno) para poder rastrear
 * una operación entre equipos, y lo devuelve en la respuesta.
 */
class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->header('X-Correlation-Id', '');
        $correlationId = preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $incoming) === 1 ? $incoming : (string) Str::uuid();

        $request->attributes->set('correlation_id', $correlationId);

        $response = $next($request);
        $response->headers->set('X-Correlation-Id', $correlationId);

        return $response;
    }
}
