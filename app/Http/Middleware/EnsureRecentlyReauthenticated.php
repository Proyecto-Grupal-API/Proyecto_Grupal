<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Modulo 1.7 - Flujo de reautenticacion para acciones sensibles.
 *
 * Exige que el usuario haya confirmado su contraseña recientemente
 * (ver Auth\AuthController::reauthenticate) antes de permitir
 * acciones como revocar una sesion o quitarle confianza a un
 * dispositivo. Si no hay una confirmacion vigente, responde 428 para
 * que el frontend muestre el modal de reautenticacion.
 */
class EnsureRecentlyReauthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $validMinutes = (int) env('REAUTH_VALID_MINUTES', 5);
        $reauthAt = $request->session()->get('reauth_at');

        $isFresh = $reauthAt && now()->diffInMinutes($reauthAt) <= $validMinutes;

        if (! $isFresh) {
            if ($request->expectsJson() || $request->header('X-Inertia')) {
                return response()->json([
                    'message' => 'Se requiere confirmar tu contraseña para continuar.',
                    'reauth_required' => true,
                ], 428);
            }

            return redirect()->back()->with('error', 'Confirma tu contraseña para continuar.');
        }

        return $next($request);
    }
}
