<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

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
        abort_unless(is_string($secret) && strlen($secret) >= 20 && preg_match('/^[0-9]{10}$/', $time) && abs(time() - (int) $time) <= 300, 401);
        abort_unless(strlen($request->getContent()) <= 65536, 413);
        $value = $time.'|'.$request->method().'|/'.$request->path().'|'.hash('sha256', $request->getContent());
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $signature) && hash_equals(hash_hmac('sha256', $value, $secret), $signature), 401);
        $request->attributes->set('platform_source', $domain);

        return $next($request);
    }
}
