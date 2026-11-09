<?php

namespace App\Http\Middleware;

use App\Models\SecurityEvent;
use App\Models\UserSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Modulo 1.7 - Cierre remoto de sesion.
 *
 * Si el estudiante revoco esta sesion desde otro dispositivo (o desde
 * la propia pantalla de "Dispositivos y sesiones"), este middleware la
 * detecta en el siguiente request de este navegador y fuerza el
 * logout inmediatamente, dejando rastro en security_events.
 */
class EnsureSessionIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            $cdSessionId = $request->session()->get('cd_session_id');
            $session = $cdSessionId ? UserSession::find($cdSessionId) : null;

            if ($session && $session->isRevoked()) {
                $userId = (string) $request->user()->_id;

                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                SecurityEvent::log([
                    'user_id' => $userId,
                    'session_id' => $cdSessionId,
                    'type' => 'session_forced_logout',
                    'severity' => 'warning',
                    'ip_address' => $request->ip(),
                ]);

                return redirect()->route('login')
                    ->with('status', 'Tu sesión fue cerrada de forma remota desde otro dispositivo.');
            }
        }

        return $next($request);
    }
}
