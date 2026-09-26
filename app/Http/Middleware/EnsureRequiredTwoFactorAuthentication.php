<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRequiredTwoFactorAuthentication
{
    private const ENROLLMENT_ROUTES = [
        'two-factor.enrollment',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.disable', // Cancel a pending setup so it can be restarted.
        'password.confirm',
        'password.confirm.custom.store',
        'password.confirm.store',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->requiresTwoFactorAuthentication()) {
            return $next($request);
        }

        if ($request->routeIs('two-factor.disable') && $user->two_factor_enabled) {
            abort(403, 'Tu cuenta requiere autenticación de dos factores.');
        }

        if ($user->two_factor_enabled || $request->routeIs(...self::ENROLLMENT_ROUTES)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->headers->has('X-Inertia')) {
            return response()->json([
                'message' => 'Debes confirmar la autenticación de dos factores para continuar.',
                'enrollment_url' => route('two-factor.enrollment'),
            ], 423);
        }

        return redirect()->route('two-factor.enrollment');
    }
}
