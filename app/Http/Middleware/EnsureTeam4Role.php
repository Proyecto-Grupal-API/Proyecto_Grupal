<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EnsureTeam4Role
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();
        $headerRole = $request->header('X-User-Role');

        /*
         * CORRECCIÓN #6:
         * X-User-Role no es una fuente confiable de autorización en producción.
         * Solo se conserva como fallback de desarrollo mientras el Equipo 1
         * entrega su integración real de identidad/roles.
         */
        if (config('app.env') === 'production' && $headerRole !== null) {
            return response()->json([
                'message' => 'El header X-User-Role no está permitido en producción.',
            ], 403);
        }

        $role = $user?->role;

        if ($role === null && $headerRole !== null) {
            if (config('app.env') !== 'production') {
                Log::warning('Fallback X-User-Role utilizado en Equipo 4. Solo válido para desarrollo.', [
                    'path' => $request->path(),
                    'role' => $headerRole,
                ]);
            }

            $role = $headerRole;
        }

        if ($role === null) {
            return response()->json([
                'message' => 'No autenticado.',
            ], 401);
        }

        if ($roles && !in_array($role, $roles, true)) {
            return response()->json([
                'message' => 'No autorizado para esta operación.',
            ], 403);
        }

        return $next($request);
    }
}
