<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPermission
{
    /**
     * Valida que el usuario autenticado tenga el permiso requerido.
     * Uso en rutas: ->middleware(['auth:sanctum', 'permission:rewards.manage'])
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'error' => 'No autenticado.',
            ], 401);
        }

        if (! $user->isActive()) {
            return response()->json([
                'success' => false,
                'error' => 'La cuenta de usuario está inactiva o suspendida.',
            ], 403);
        }

        // platform_admin tiene todos los permisos
        if ($user->role === 'platform_admin' || $user->hasPermission($permission)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'error' => "Acceso denegado: permiso requerido [{$permission}].",
            'required_permission' => $permission,
            'user_role' => $user->role,
        ], 403);
    }
}
