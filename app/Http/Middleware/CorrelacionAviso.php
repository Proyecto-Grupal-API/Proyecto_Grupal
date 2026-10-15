<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CorrelacionAviso
{
    public function handle(Request $request, Closure $next)
    {
        $request->attributes->set('aviso_correlacion', (string) Str::uuid());
        return $next($request)->header('X-Correlation-Id', $request->attributes->get('aviso_correlacion'))
            ->header('Cache-Control', 'private, no-store');
    }
}
