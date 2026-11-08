<?php

namespace App\Services;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/** TOTP step-up for an already authenticated human. Never uses reauth_at or login.id. */
class RecentTwoFactor
{
    public const VALID_SECONDS = 300;

    public function __construct(private readonly IdentityService $identity,
        private readonly TwoFactorAuthenticationProvider $provider) {}

    public function hasActiveSession(Request $request): bool
    {
        return $this->activeSession($request) !== null;
    }

    public function confirm(Request $request, string $code): void
    {
        $session = $this->activeSession($request);
        if ($session === null || ! $this->configured($request->user('web'))) {
            throw new AuthorizationException('A current identity session and confirmed TOTP are required.');
        }
        $binding = $this->binding($request, $session);
        $valid = false;
        if (preg_match('/\A[0-9]{6}\z/', $code)) {
            try {
                $secret = Fortify::currentEncrypter()->decrypt($request->user('web')->two_factor_secret);
                if (is_string($secret) && preg_match('/\A[A-Z2-7]+\z/', $secret)) {
                    $valid = $this->provider->verify($secret, $code);
                }
            } catch (DecryptException) {
                // A damaged secret is never evidence of successful authentication.
            }
        }
        if (! $valid) {
            $this->audit($request, $session, false);
            throw ValidationException::withMessages(['code' => 'El código TOTP no es válido.']);
        }
        $verifiedAt = now()->getTimestamp();
        $current = $this->activeSession($request);
        if ($current === null || $binding !== $this->binding($request, $current)) {
            throw new AuthorizationException('The authenticated session changed during confirmation.');
        }
        // Audit failure must prevent authorizing a sensitive operation.
        $this->audit($request, $current, true);
        if (! $this->record($current, [...$binding, 'verified_at' => $verifiedAt])) {
            throw new AuthorizationException('The identity session is no longer current.');
        }
    }

    public function isRecent(Request $request): bool
    {
        $session = $this->activeSession($request);
        if ($session === null || ! $this->configured($request->user('web'))) {
            return false;
        }
        $evidence = $session->recent_two_factor;
        if ($evidence instanceof \MongoDB\Model\BSONDocument) {
            $evidence = $evidence->getArrayCopy();
        }
        $binding = $this->binding($request, $session);
        if (! is_array($evidence) || array_diff(array_keys($evidence), [...array_keys($binding), 'verified_at']) !== []
            || ! is_int($evidence['verified_at'] ?? null)) {
            return false;
        }
        foreach ($binding as $key => $value) {
            if (($evidence[$key] ?? null) !== $value) {
                return false;
            }
        }
        $now = now()->getTimestamp();

        return $evidence['verified_at'] <= $now && $now - $evidence['verified_at'] < self::VALID_SECONDS;
    }

    protected function activeSession(Request $request): ?UserSession
    {
        $user = $request->user('web');
        if (! $user instanceof User || ! $request->hasSession() || $request->session()->getId() === '') {
            return null;
        }
        $evidence = $this->identity->activeFinancialSession((string) $user->getKey(), $request->session()->get('cd_session_id'));
        if ($evidence === null) {
            return null;
        }
        return UserSession::whereKey($evidence['session_id'])->where('user_id', (string) $user->getKey())
            ->whereNull('revoked_at')->first();
    }

    private function configured(User $user): bool
    {
        return is_string($user->two_factor_secret) && $user->two_factor_secret !== ''
            && $user->two_factor_confirmed_at !== null;
    }

    private function binding(Request $request, UserSession $session): array
    {
        return ['user_id' => (string) $request->user('web')->getKey(), 'identity_session_id' => (string) $session->getKey(),
            'laravel_session_hash' => hash('sha256', $request->session()->getId()),
            'factor_hash' => hash('sha256', $request->user('web')->two_factor_secret.'|'.(string) $request->user('web')->two_factor_confirmed_at)];
    }

    protected function record(UserSession $session, array $evidence): bool
    {
        return UserSession::whereKey($session->getKey())->where('user_id', $session->user_id)
            ->whereNull('revoked_at')->update(['recent_two_factor' => $evidence]) === 1;
    }

    protected function audit(Request $request, UserSession $session, bool $success): void
    {
        SecurityEvent::log(['user_id' => (string) $request->user('web')->getKey(), 'session_id' => (string) $session->getKey(),
            'type' => $success ? 'recent_two_factor_confirmed' : 'recent_two_factor_failed',
            'severity' => $success ? 'info' : 'warning', 'ip_address' => $request->ip(),
            'metadata' => ['method' => 'totp', 'valid_seconds' => self::VALID_SECONDS]]);
    }
}
