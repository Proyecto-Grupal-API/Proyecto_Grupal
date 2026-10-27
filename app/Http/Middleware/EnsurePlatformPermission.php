<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class EnsurePlatformPermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        abort_unless($request->attributes->get('platform_actor')?->hasPermission($permission), 403, 'No tienes permiso para esta operación de Recompensas.');

        return $next($request);
    }
}
