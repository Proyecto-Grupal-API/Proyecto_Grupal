<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware dual de autenticación para integraciones inter-equipos (Módulo 7.10).
 *
 * Acepta dos métodos de autenticación:
 * 1. Firma HMAC-SHA256 (Service-to-Service): Cabeceras X-Team-Timestamp + X-Team-Signature
 *    (o X-Campus-Timestamp + X-Campus-Signature). Verifica contra el secreto del equipo
 *    identificado por X-Team-Source-Domain (team4, identity, team3, etc.).
 * 2. Token Bearer Sanctum: Usuario con rol 'system', 'admin', 'platform_admin' o permiso 'integrations.ingest'.
 *
 * Para Equipo 1 (Identidad): usa X-Team-Source-Domain: identity
 *   y config('services.team1.shared_secret') / env('TEAM1_SERVICE_SECRET').
 * Para Equipo 4 (Inventario): X-Team-Source-Domain: inventory / team4
 *   y config('services.team4.shared_secret').
 */
class EnsureIntegrationAuthorized
{
    /**
     * Mapa de source_domain a clave de configuración de secreto.
     * Permite extender fácilmente para nuevos equipos sin modificar lógica.
     */
    private array $domainSecretMap = [
        'identity' => 'services.team1.shared_secret',
        'team1'    => 'services.team1.shared_secret',
        'inventory' => 'services.team4.shared_secret',
        'team4'    => 'services.team4.shared_secret',
        'team3'    => 'services.team4.shared_secret', // Equipo 3 comparte mecanismo; actualizar si acuerdan secreto propio
        'marketplace' => 'services.team4.shared_secret',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Team-Signature') ?? $request->header('X-Campus-Signature');
        $timestamp = $request->header('X-Team-Timestamp') ?? $request->header('X-Campus-Timestamp');

        // ── 1. Verificación por firma HMAC si se envían cabeceras de firma ──
        if ($signature && $timestamp) {
            // Determinar el secreto correcto según el equipo remitente
            $sourceDomain = strtolower((string) ($request->header('X-Team-Source-Domain') ?? ''));
            $secretConfigKey = $this->domainSecretMap[$sourceDomain] ?? 'services.team4.shared_secret';
            $secret = (string) config($secretConfigKey);
            $allowedClockSkew = 300; // 5 minutos de tolerancia

            if (! ctype_digit((string) $timestamp)) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Firma HMAC inválida: timestamp no numérico.',
                ], 401);
            }

            $skew = abs(time() - (int) $timestamp);
            if ($skew > $allowedClockSkew) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Firma HMAC expirada (desfase de reloj superior a 300s).',
                ], 401);
            }

            $path     = '/' . ltrim($request->path(), '/');
            $bodyHash = hash('sha256', $request->getContent());
            $payload  = $timestamp . '|' . strtoupper($request->method()) . '|' . $path . '|' . $bodyHash;
            $expected = hash_hmac('sha256', $payload, $secret);

            if (! hash_equals($expected, $signature)) {
                Log::warning('Firma HMAC de integración rechazada', [
                    'ip'         => $request->ip(),
                    'path'       => $path,
                    'received'   => $signature,
                    'skew_sec'   => $skew,
                ]);

                return response()->json([
                    'success' => false,
                    'error'   => 'Firma HMAC de integración inválida.',
                ], 401);
            }

            // Autenticación por HMAC exitosa
            $request->attributes->set('auth_method', 'hmac');
            return $next($request);
        }

        // ── 2. Verificación por Bearer Token ──
        $bearerToken = $request->bearerToken();
        if ($bearerToken) {
            // A. Token de Servicio estático inter-equipos (utilizado por el comando events:publish del Equipo 1 vía EVENTS_SINK_TOKEN)
            $team1Secret = (string) config('services.team1.service_token', config('services.team1.shared_secret'));
            $team4Secret = (string) config('services.team4.shared_secret');

            if ($team1Secret !== '' && hash_equals($team1Secret, $bearerToken)) {
                $request->attributes->set('auth_method', 'service_token');
                $request->attributes->set('service_source', 'team1');
                return $next($request);
            }

            if ($team4Secret !== '' && hash_equals($team4Secret, $bearerToken)) {
                $request->attributes->set('auth_method', 'service_token');
                $request->attributes->set('service_source', 'team4');
                return $next($request);
            }

            // B. Token de usuario Sanctum (administrador o servicio con permisos específicos)
            $user = auth('sanctum')->user();
            if ($user) {
                if ($user->hasRole('system')
                    || $user->hasRole('platform_admin')
                    || $user->hasRole('admin')
                    || $user->hasPermission('integrations.ingest')
                ) {
                    auth()->setUser($user);
                    $request->attributes->set('auth_method', 'sanctum');
                    return $next($request);
                }

                return response()->json([
                    'success' => false,
                    'error'   => 'Permisos insuficientes para ingerir eventos de integración.',
                ], 403);
            }
        }

        return response()->json([
            'success' => false,
            'error'   => 'No autorizado: se requiere cabecera de firma HMAC (X-Team-Signature / X-Team-Timestamp) o Token Bearer válido (EVENTS_SINK_TOKEN o Sanctum).',
        ], 401);
    }
}
