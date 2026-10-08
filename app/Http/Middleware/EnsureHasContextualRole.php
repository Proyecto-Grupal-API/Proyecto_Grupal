<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHasContextualRole
{
    public function handle(Request $request, Closure $next, string $role, ?string $scopeType = null): Response
    {
        $user = $request->user();
        $scopeId = $scopeType === null
            ? null
            : ($request->route('scopeId') ?? $request->input('scope_id'));

        if (! $user || ! $user->hasRole($role, $scopeType, $scopeId)) {
            abort(403, 'No tienes los permisos requeridos en este contexto.');
        }

        return $next($request);
    }
}
