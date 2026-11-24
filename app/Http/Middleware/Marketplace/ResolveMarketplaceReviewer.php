<?php

namespace App\Http\Middleware\Marketplace;

use App\Http\Middleware\EnsureHasContextualRole;
use App\Support\Authorization\ServiceStaff;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ResolveMarketplaceReviewer
{
    public function handle(Request $request, Closure $next)
    {
        $allowed = false;
        try {
            app(EnsureHasContextualRole::class)->handle($request, function () use (&$allowed) {
                $allowed = true;

                return response('', 204);
            }, 'admin');
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 403) {
                throw $e;
            }
        }
        // La revisión institucional también puede ser un cargo propio de Tienda, asignado por Identidad en el contexto
        // de servicio, sin necesidad del administrador global.
        $allowed = $allowed || ServiceStaff::manages($request->user(), (string) config('marketplace.reviewer_context', 'tienda'));
        $request->attributes->set('marketplace_reviewer', $allowed);

        return $next($request);
    }
}
