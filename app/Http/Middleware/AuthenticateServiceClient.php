<?php

namespace App\Http\Middleware;

use App\Http\Controllers\StudentServices\Benefits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticación servidor a servidor para las APIs del Equipo 5.
 *
 * Se registra con el alias `oauth.service`, el MISMO que usa el Equipo 1
 * para su ValidateServiceToken (tokens OAuth client_credentials). Uso:
 * `->middleware('oauth.service:services:benefits:write')`.
 *
 * - En la app integrada, el alias apunta al middleware del Equipo 1 y
 *   este archivo deja de usarse: las rutas no cambian.
 * - En esta rama (sin el OAuth del Equipo 1) valida un token estático
 *   configurado en .env para desarrollo y pruebas.
 *
 * En ambos casos deja en el request `oauth_client_id` y `oauth_scope`.
 */
class AuthenticateServiceClient
{
    public function handle(Request $request, Closure $next, ?string $requiredScope = null): Response
    {
        $header = (string) $request->header('Authorization', '');

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) !== 1) {
            return ApiResponse::error($request, 'no_autenticado', 'Se requiere un token de servicio (Bearer).', 401)
                ->header('WWW-Authenticate', 'Bearer');
        }

        $client = $this->clientFor(trim($matches[1]));

        if ($client === null) {
            return ApiResponse::error($request, 'no_autenticado', 'El token de servicio no es válido.', 401)
                ->header('WWW-Authenticate', 'Bearer');
        }

        if ($requiredScope !== null && ! in_array($requiredScope, $client['scopes'], true)) {
            return ApiResponse::error($request, 'sin_permiso', "El token no tiene el scope requerido: {$requiredScope}.", 403);
        }

        $request->attributes->set('oauth_client_id', $client['id']);
        $request->attributes->set('oauth_scope', implode(' ', $client['scopes']));

        return $next($request);
    }

    /**
     * @return array{id: string, scopes: list<string>}|null
     */
    private function clientFor(string $token): ?array
    {
        foreach ((array) config('services.student_services_api.clients', []) as $client) {
            $expected = (string) ($client['token'] ?? '');

            if ($expected !== '' && hash_equals($expected, $token)) {
                return [
                    'id' => (string) $client['id'],
                    'scopes' => array_values(array_filter(preg_split('/\s+/', (string) ($client['scopes'] ?? '')) ?: [])),
                ];
            }
        }

        return null;
    }
}
