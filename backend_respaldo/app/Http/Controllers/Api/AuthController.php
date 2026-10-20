<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditLog;
use App\Models\PointsAccount;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController
{
    /**
     * Inicio de sesión API mediante credenciales (email y password).
     * Emite un token Bearer de Sanctum.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', strtolower(trim($validated['email'])))->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'error'   => 'Credenciales inválidas. Verifica tu correo y contraseña.',
            ], 422);
        }

        if (! $user->isActive()) {
            return response()->json([
                'success' => false,
                'error'   => 'Tu cuenta se encuentra inactiva o suspendida. Contacta al administrador.',
            ], 403);
        }

        // Actualiza timestamp de último login
        $user->update(['last_login_at' => now()]);

        // Genera token Sanctum
        $token = $user->createToken('api_token')->plainTextToken;

        // Registro de auditoría
        AuditLog::create([
            'actor_id'      => (string) $user->getKey(),
            'actor_role'    => $user->role,
            'action'        => 'auth.login',
            'entity_type'   => 'User',
            'entity_id'     => (string) $user->getKey(),
            'ip_address'    => $request->ip(),
            'device'        => $request->userAgent(),
            'source_domain' => 'auth',
        ]);

        return response()->json([
            'success'   => true,
            'token'     => $token,
            'user'      => $this->buildUserProfile($user),
            'home_area' => Permissions::homeArea($user->role),
        ]);
    }

    /**
     * Cierre de sesión: revoca el token actual del usuario autenticado.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            $user->currentAccessToken()?->delete();

            AuditLog::create([
                'actor_id'      => (string) $user->getKey(),
                'actor_role'    => $user->role,
                'action'        => 'auth.logout',
                'entity_type'   => 'User',
                'entity_id'     => (string) $user->getKey(),
                'ip_address'    => $request->ip(),
                'device'        => $request->userAgent(),
                'source_domain' => 'auth',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    /**
     * Consulta del perfil del usuario autenticado.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['success' => false, 'error' => 'No autenticado.'], 401);
        }

        return response()->json([
            'success'   => true,
            'user'      => $this->buildUserProfile($user),
            'home_area' => Permissions::homeArea($user->role),
        ]);
    }

    /**
     * Helper de desarrollo: permite alternar de usuario/rol fácilmente para pruebas locales.
     * Solo disponible en APP_ENV=local o testing.
     */
    public function switchDemoUser(Request $request): JsonResponse
    {
        if (! app()->environment('local', 'testing')) {
            return response()->json(['success' => false, 'error' => 'No disponible en este entorno.'], 403);
        }

        $validated = $request->validate([
            'role' => 'required|string|in:' . implode(',', User::ROLES),
        ]);

        $user = User::where('role', $validated['role'])->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'error'   => "No hay ningún usuario con rol [{$validated['role']}] en la base de datos.",
            ], 404);
        }

        $token = $user->createToken('demo_token')->plainTextToken;

        return response()->json([
            'success'   => true,
            'token'     => $token,
            'user'      => $this->buildUserProfile($user),
            'home_area' => Permissions::homeArea($user->role),
        ]);
    }

    /**
     * Construye el payload del perfil de usuario con permisos y balance de puntos si aplica.
     */
    private function buildUserProfile(User $user): array
    {
        $profile = $user->toPublicArray();
        $profile['permissions'] = $user->permissions();

        // Si es alumno, adjunta resumen de puntos
        if ($user->role === User::ROLE_STUDENT) {
            $account = PointsAccount::where('student_id', (string) $user->getKey())->first();
            $profile['points'] = [
                'balance'           => $account?->balance ?? 0,
                'pending_balance'   => $account?->pending_balance ?? 0,
                'lifetime_earned'   => $account?->lifetime_earned ?? 0,
                'lifetime_redeemed' => $account?->lifetime_redeemed ?? 0,
            ];
        }

        return $profile;
    }
}
