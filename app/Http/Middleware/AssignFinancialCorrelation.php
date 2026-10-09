<?php

namespace App\Http\Middleware;

use App\Domains\Financial\Support\FinancialCorrelation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2.10 Toma la correlación de X-Correlation-Id (o X-Request-Id) o genera
 * una, la deja disponible para el dominio financiero y la devuelve en la
 * cabecera X-Correlation-Id.
 */
class AssignFinancialCorrelation
{
    public function __construct(private readonly FinancialCorrelation $correlation)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->correlation->set(
            $request->header('X-Correlation-Id') ?? $request->header('X-Request-Id')
        );

        $response = $next($request);
        $response->headers->set('X-Correlation-Id', $this->correlation->id());

        return $response;
    }
}
