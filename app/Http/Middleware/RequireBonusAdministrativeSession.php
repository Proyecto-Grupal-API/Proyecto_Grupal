<?php

namespace App\Http\Middleware;

use App\Services\IdentityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Native session evidence only; never grants financial authority or proves fresh second factor. */
final class RequireBonusAdministrativeSession
{
    public function __construct(private readonly IdentityService $identity) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->identity->hasActiveAccountSession(
            (string) $request->user()?->getKey(), $request->session()->get('cd_session_id')
        ), 403, 'La cuenta o la sesión de Identidad no está vigente.');

        return $next($request);
    }
}
