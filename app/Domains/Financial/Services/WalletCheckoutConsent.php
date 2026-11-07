<?php

namespace App\Domains\Financial\Services;

use App\Services\IdentityService;
use Illuminate\Http\Request;

class WalletCheckoutConsent
{
    public function __construct(private readonly IdentityService $identity) {}

    public function ensureActiveSession(Request $request): void
    {
        abort_unless($this->identity->hasActiveAccountSession(
            (string) $request->user()->getKey(), $request->session()->get('cd_session_id')
        ), 403, 'La cuenta o la sesión de Identidad no está vigente.');
    }

    public function passwordRecent(Request $request, int $max = 300): bool
    {
        abort_unless($max >= 60 && $max <= 900, 503, 'Configuracion de confirmacion no valida.');
        $at = $request->session()->get('auth.password_confirmed_at');

        return is_int($at) && $at <= now()->timestamp && $at >= now()->timestamp - $max;
    }

    public function reviewToken(Request $request, string $id, string $digest): string
    {
        $key = config('app.key');
        abort_unless(is_string($key) && strlen($key) >= 32, 503, 'Clave de aplicacion no valida.');

        return hash_hmac('sha256', implode('|', [
            strtolower($id), $digest, (string) $request->user()->getKey(),
            $request->session()->getId(), $request->session()->get('cd_session_id'),
        ]), $key);
    }
}
