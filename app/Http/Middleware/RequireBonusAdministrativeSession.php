<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\UserSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Native session evidence only; never grants financial authority or proves fresh second factor. */
final class RequireBonusAdministrativeSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->routeIs('financial.bonuses.admin.issue', 'financial.bonuses.admin.cancel')) {
            return $next($request);
        }
        $id = (string) $request->user()?->getKey();
        abort_unless(preg_match('/\A[0-9a-fA-F]{24}\z/', $id), 403);
        $user = User::find($id);
        abort_unless($user && !$user->deleted_at && !$user->account_activation_pending
            && !$user->must_change_password && $user->hasVerifiedEmail(), 403, 'La cuenta no esta habilitada.');
        $sessionId = $request->session()->get('cd_session_id');
        abort_unless(is_string($sessionId) && preg_match('/\A[0-9a-fA-F]{24}\z/', $sessionId), 403,
            'Se requiere una sesion registrada en Identidad.');
        $session = UserSession::find($sessionId);
        abort_unless($session && !$session->isRevoked()
            && strtolower((string) $session->user_id) === strtolower($id), 403, 'La sesion ya no esta vigente.');
        return $next($request);
    }
}
