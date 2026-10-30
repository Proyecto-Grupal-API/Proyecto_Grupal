<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SkipCsrfForWebhook
{
    public function handle(Request $request, Closure $next)
    {
        // Desactivar la verificación CSRF para esta petición
        $request->attributes->set('skip_csrf', true);
        return $next($request);
    }
}