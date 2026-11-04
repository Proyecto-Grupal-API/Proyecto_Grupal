<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Autentica llamadas entrantes de otros equipos (Equipo 5, Equipo 6, etc.) a la
 * ingesta de plataforma (/api/v1/plataforma/eventos|auditoria) por firma HMAC-SHA256
 * con un secreto propio por dominio (config('platform.ingest.secrets')), en vez de
 * Sanctum/cuentas de servicio. Cada equipo manda:
 *
 *   X-Platform-Domain:    community            (su dominio, debe matchear source_domain)
 *   X-Platform-Timestamp: <unix ts, 10 dígitos> (ventana de reintento: 300s)
 *   X-Platform-Signature: hmac_sha256(ts|METHOD|/path|sha256(body), secreto_del_dominio)
 *
 * Esta implementación es la misma ya probada en el repo grupal (monolito con todos
 * los equipos), portada aquí para que el receptor de Equipo 7 quede consistente con
 * lo que Equipo 6 ya tiene construido y agendado (comunidad:publicar-plataforma).
 */
final class AuthenticatePlatformSource
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('platform.ingest.enabled') === true, 503, 'Recepción de plataforma deshabilitada.');

        $domain = $request->header('X-Platform-Domain', '');
        $secrets = config('platform.ingest.secrets', []);
        $secret = $secrets[$domain] ?? null;

        $time = $request->header('X-Platform-Timestamp', '');
        $signature = $request->header('X-Platform-Signature', '');

        abort_unless(
            is_string($secret) && strlen($secret) >= 20
            && preg_match('/^[0-9]{10}$/', $time)
            && abs(time() - (int) $time) <= 300,
            401
        );

        abort_unless(strlen($request->getContent()) <= 65536, 413);

        $value = $time.'|'.$request->method().'|/'.$request->path().'|'.hash('sha256', $request->getContent());

        abort_unless(
            preg_match('/^[a-f0-9]{64}$/', $signature)
            && hash_equals(hash_hmac('sha256', $value, $secret), $signature),
            401
        );

        $request->attributes->set('platform_source', $domain);

        return $next($request);
    }
}
