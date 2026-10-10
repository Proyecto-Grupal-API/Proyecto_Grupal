<?php
namespace App\Http\Middleware;
use App\Domains\Identity\Exceptions\AuthorizationDependencyUnavailable;
use Closure;
use Illuminate\Http\Request;
final class RenderBonusRoleDependencyFailures
{
    public function handle(Request $request, Closure $next)
    {
        try { return $next($request); }
        catch (AuthorizationDependencyUnavailable) { return response()->json(['message' => 'La autorización de roles no está disponible. No se ejecutó la operación.', 'code' => 'BONUS_AUTHORIZATION_UNAVAILABLE'], 503); }
    }
}
