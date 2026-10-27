<?php

namespace App\Http\Middleware;

use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use MongoDB\Driver\Exception\Exception;

class RenderMarketplaceCheckoutFailures
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $response = $next($request);
        } catch (FinancialDependencyUnavailableException $e) {
            report($e);
            $response = response()->json(['message' => 'No se pudo comprobar una dependencia de seguridad.'], 503);
        } catch (Exception $e) {
            report($e);
            $response = response()->json(['message' => 'Identidad no esta disponible para comprobar la operacion.'], 503);
        } catch (UniqueConstraintViolationException $e) {
            $response = response()->json(['message' => 'Hay una operacion concurrente o un pedido ya vinculado. Consulta el mismo pedido antes de reintentar.'], 409);
        }

        return $response->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'same-origin');
    }
}
