<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class Team4ServiceSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.team4.shared_secret', '');
        $allowedClockSkew = (int) config('services.team4.allowed_clock_skew', 300);

        if ($secret === '') {
            return response()->json([
                'message' => 'Integración no configurada.',
            ], 503);
        }

        $timestamp = (string) $request->header('X-Team-Timestamp', '');
        $signature = (string) $request->header('X-Team-Signature', '');
        $path = '/' . ltrim($request->path(), '/');

        if (!ctype_digit($timestamp)) {
            // Corrección B: se audita el intento fallido para poder detectar
            // ataques o clientes mal configurados.
            Log::warning('Intento de integración rechazado', [
                'ip' => $request->ip(),
                'path' => $path,
                'method' => $request->method(),
                'client_timestamp' => $timestamp,
                'server_timestamp' => time(),
                'skew_seconds' => null,
                'reason' => 'invalid_timestamp',
            ]);

            return response()->json([
                'message' => 'Firma de integración inválida.',
            ], 401);
        }

        $bodyHash = hash('sha256', $request->getContent());
        $payload = $timestamp . '|' . $request->method() . '|' . $path . '|' . $bodyHash;
        $expected = hash_hmac('sha256', $payload, $secret);

        $skew = abs(time() - (int) $timestamp);

        if ($skew > $allowedClockSkew) {
            // Corrección B: se audita el intento fallido para poder detectar
            // ataques o clientes mal configurados.
            Log::warning('Intento de integración rechazado', [
                'ip' => $request->ip(),
                'path' => $path,
                'method' => $request->method(),
                'client_timestamp' => $timestamp,
                'server_timestamp' => time(),
                'skew_seconds' => $skew,
                'reason' => 'clock_skew_exceeded',
            ]);

            return response()->json([
                'message' => 'Firma de integración inválida.',
            ], 401);
        }

        if (!hash_equals($expected, $signature)) {
            // Corrección B: se audita el intento fallido para poder detectar
            // ataques o clientes mal configurados.
            Log::warning('Intento de integración rechazado', [
                'ip' => $request->ip(),
                'path' => $path,
                'method' => $request->method(),
                'client_timestamp' => $timestamp,
                'server_timestamp' => time(),
                'skew_seconds' => $skew,
                'reason' => 'signature_mismatch',
            ]);

            return response()->json([
                'message' => 'Firma de integración inválida.',
            ], 401);
        }

        return $next($request);
    }
}
