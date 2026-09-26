<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Modulo 1.7 - Flujo de reautenticacion para acciones sensibles.
 *
 * El login/logout "normales" los resuelve Laravel Fortify (Modulo
 * 1.2). Este controlador cubre el paso adicional que exige el diseno
 * antes de ejecutar operaciones sensibles sobre sesiones/dispositivos:
 * confirmar la contraseña del usuario en la misma sesion de navegador.
 */
class AuthController extends Controller
{
    public function reauthenticate(Request $request)
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['password'], $user->password)) {
            SecurityEvent::log([
                'user_id' => (string) $user->_id,
                'session_id' => $request->session()->get('cd_session_id'),
                'type' => 'reauth_failed',
                'severity' => 'warning',
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'La contraseña no es correcta.',
            ], 422);
        }

        $request->session()->put('reauth_at', now());

        SecurityEvent::log([
            'user_id' => (string) $user->_id,
            'session_id' => $request->session()->get('cd_session_id'),
            'type' => 'reauth_success',
            'severity' => 'info',
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'ok' => true,
            'reauth_valid_minutes' => (int) env('REAUTH_VALID_MINUTES', 5),
        ]);
    }
}
