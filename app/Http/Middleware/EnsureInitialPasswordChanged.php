<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInitialPasswordChanged
{
    private const ALLOWED_ROUTES = [
        'password.initial.edit',
        'password.initial.update',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password !== true || $request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->headers->has('X-Inertia')) {
            return response()->json([
                'code' => 'initial_password_change_required',
                'message' => 'Debes establecer tu contraseña definitiva antes de continuar.',
            ], 423);
        }

        return redirect()->route('password.initial.edit');
    }
}
