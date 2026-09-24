<?php

namespace App\Services;

use App\Models\Device;
use App\Models\QrToken;
use App\Models\QrValidation;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\UserSession;
use App\Support\QrLookupHash;
use App\Support\IssuedQrToken;
use App\Support\QrSecretIntegrityException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MongoDB\Driver\Exception\BulkWriteException;
use RuntimeException;

/**
 * Servicio interno de identidad (base del modulo 1.10), usado por los
 * modulos 1.6 - Identidad QR y 1.7 - Dispositivos y sesiones.
 *
 * Los demas equipos NO deben reimplementar esta logica: deben resolver
 * identidad/QR/dispositivos a traves de este servicio o del contrato
 * expuesto en routes/api.php (/api/v1/identity/*) cuando se construya
 * el modulo 1.10.
 */
class IdentityService
{
    /**
     * ---------------------------------------------------------------
     * Modulo 1.6 - Identidad QR
     * ---------------------------------------------------------------
     */

    /**
     * Genera un nuevo token QR dinamico para el usuario, revocando
     * cualquier token dinamico previo aun vigente, para garantizar que
     * solo exista un QR "activo" a la vez por alumno.
     */
    public function issueDynamicQrToken(User $user, ?string $purpose = null): IssuedQrToken
    {
        $ttl = (int) env('QR_IDENTITY_TTL_SECONDS', 30);
        $code = QrToken::generateCode();
        $codeHash = QrLookupHash::code($code);

        QrToken::where('user_id', (string) $user->_id)
            ->where('type', 'dynamic')
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $token = null;

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $shortCode = (string) random_int(100000, 999999);
            $shortCodeHash = QrLookupHash::shortCode($shortCode);

            // A reservation survives consumption/revocation until expiry plus
            // grace. Reclaim only an expired reservation for this candidate.
            QrToken::where('short_code_hash', $shortCodeHash)
                ->where('short_code_claimed', true)
                ->where('expires_at', '<=', now()->subSeconds((int) config('qr.short_code_reuse_grace_seconds')))
                ->update(['short_code_claimed' => false]);

            if ($this->usableShortCodeExists($shortCode, $shortCodeHash)) {
                continue;
            }

            try {
                $token = QrToken::create([
                    'user_id' => (string) $user->_id,
                    'code_hash' => $codeHash,
                    'short_code_hash' => $shortCodeHash,
                    'short_code_claimed' => true,
                    'type' => 'dynamic',
                    'purpose' => $purpose,
                    'expires_at' => now()->addSeconds($ttl),
                ]);
                break;
            } catch (BulkWriteException $exception) {
                if ($exception->getCode() !== 11000 || ! str_contains($exception->getMessage(), 'qr_short_code_claim_unique')) {
                    throw $exception;
                }
            }
        }

        if ($token === null) {
            throw new RuntimeException('Unable to reserve a unique QR short code.');
        }

        SecurityEvent::log([
            'user_id' => (string) $user->_id,
            'type' => 'qr_generated',
            'severity' => 'info',
            'metadata' => ['qr_token_id' => (string) $token->_id, 'ttl_seconds' => $ttl],
        ]);

        return new IssuedQrToken($token, $code, $shortCode);
    }

    /**
     * Genera (o reutiliza si sigue vigente) el QR "fijo" de
     * identificacion del estudiante, con una vigencia mas larga.
     */
    public function issueIdentificationQrToken(User $user): IssuedQrToken
    {
        $existing = QrToken::where('user_id', (string) $user->_id)
            ->where('type', 'identification')
            ->whereNull('revoked_at')
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($existing) {
            try {
                return new IssuedQrToken($existing, $this->codeForDisplay($existing));
            } catch (QrSecretIntegrityException) {
                // The damaged token was revoked by codeForDisplay. Issue a
                // replacement once, without retrying the damaged document.
            }
        }

        $code = QrToken::generateCode();

        $token = QrToken::create([
            'user_id' => (string) $user->_id,
            'code_hash' => QrLookupHash::code($code),
            'code_encrypted' => Crypt::encryptString($code),
            'type' => 'identification',
            'purpose' => 'identidad-estudiantil',
            'expires_at' => now()->addDay(),
        ]);

        return new IssuedQrToken($token, $code);
    }

    /**
     * Firma HMAC de un codigo QR: protege el contenido del QR frente a
     * manipulacion/adivinanza de formato. La firma no reemplaza la
     * consulta a base de datos (que sigue siendo la fuente de verdad),
     * pero permite rechazar payloads corruptos o inventados antes de
     * siquiera consultar la coleccion qr_tokens.
     */
    public function signCode(string $code): string
    {
        return substr(hash_hmac('sha256', $code, config('app.key')), 0, 10);
    }

    /**
     * Payload completo que se dibuja en el QR: prefijo + codigo + firma.
     */
    public function buildQrPayload(IssuedQrToken|QrToken $issued): string
    {
        $token = $issued instanceof IssuedQrToken ? $issued->token : $issued;
        $code = $issued instanceof IssuedQrToken ? $issued->presentedCode : $this->codeForDisplay($token);
        $prefix = $token->type === 'identification' ? 'CAMPUSDIGITAL-ID:' : 'CAMPUSDIGITAL:';

        return $prefix.$code.'.'.$this->signCode($code);
    }

    private function codeForDisplay(QrToken $token): string
    {
        $plain = $token->getRawOriginal('code');
        $hash = $token->getRawOriginal('code_hash');
        $encrypted = $token->getRawOriginal('code_encrypted');

        try {
            if (is_string($encrypted) && $encrypted !== '') {
                $code = Crypt::decryptString($encrypted);
                if (! is_string($hash) || ! hash_equals($hash, QrLookupHash::code($code)) ||
                    ($plain !== null && (! is_string($plain) || ! hash_equals($plain, $code)))) {
                    throw new RuntimeException('QR identification secret integrity failure.');
                }

                return $code;
            }

            if ($encrypted !== null || ! is_string($plain) || $plain === '' ||
                ($hash !== null && (! is_string($hash) || ! hash_equals($hash, QrLookupHash::code($plain))))) {
                throw new RuntimeException('QR identification secret integrity failure.');
            }

            return $plain;
        } catch (DecryptException|RuntimeException $exception) {
            $this->recordSecretAnomaly($token, 'display_integrity');
            if ($token->type === 'identification') {
                QrToken::where('_id', $token->_id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }
            throw new QrSecretIntegrityException('QR secret unavailable.');
        }
    }

    private function recordSecretAnomaly(QrToken $token, string $reason): void
    {
        SecurityEvent::log([
            'user_id' => (string) $token->user_id,
            'type' => 'qr_secret_integrity_failed',
            'severity' => 'warning',
            'metadata' => ['qr_token_id' => (string) $token->_id, 'reason' => $reason],
        ]);
    }

    /**
     * Genera un codigo corto numerico (6 digitos) que el estudiante
     * puede dictar/teclear manualmente si no se puede escanear el QR.
     * No es criptograficamente fuerte (es de un solo uso y expira con
     * el token dinamico), pero evita colisiones con otros codigos
     * activos en este momento.
     */
    private function usableShortCodeExists(string $shortCode, string $shortCodeHash): bool
    {
        return QrToken::where(function ($query) use ($shortCode, $shortCodeHash) {
            $query->where('short_code', $shortCode)
                ->orWhere('short_code_hash', $shortCodeHash);
        })->where('type', 'dynamic')
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * Interpreta lo que escaneo/tecleo el validador: puede ser el
     * codigo "pelado", el codigo corto de respaldo, o el payload
     * completo del QR (prefijo + codigo + firma). Devuelve el codigo
     * a buscar y si la firma (cuando venia incluida) es valida.
     */
    private function parseScannedInput(string $input): array
    {
        $raw = trim($input);

        foreach (['CAMPUSDIGITAL-ID:', 'CAMPUSDIGITAL:'] as $prefix) {
            if (str_starts_with($raw, $prefix)) {
                $raw = substr($raw, strlen($prefix));
                break;
            }
        }

        if (str_contains($raw, '.')) {
            [$code, $signature] = array_pad(explode('.', $raw, 2), 2, '');

            return [
                'code' => $code,
                'is_short_code' => false,
                'signature_valid' => hash_equals($this->signCode($code), $signature),
            ];
        }

        // Codigo corto de respaldo: siempre numerico y de 6 digitos, no
        // lleva firma porque se piensa para captura manual.
        if (preg_match('/^\d{6}$/', $raw)) {
            return ['code' => $raw, 'is_short_code' => true, 'signature_valid' => null];
        }

        return ['code' => $raw, 'is_short_code' => false, 'signature_valid' => null];
    }

    /**
     * Contrato de validacion consumido por otros dominios (2, 5, 6):
     * dado un codigo/QR escaneado, determina si es valido y, de
     * serlo, resuelve la identidad del estudiante. Siempre deja
     * rastro en qr_validations.
     *
     * El consumo del token dinamico es atomico (update condicionado)
     * para que dos validaciones casi simultaneas del mismo QR no
     * puedan resolver ambas como "valid".
     *
     */
    public function validateQrCode(
        string $input,
        ?User $validatedBy,
        ?string $context,
        ?string $ip,
        ?string $validatorLabel = null,
    ): array {
        $parsed = $this->parseScannedInput($input);

        if ($parsed['signature_valid'] === false) {
            $this->logValidation(null, null, $validatedBy, $validatorLabel, 'invalid_signature', $context, $ip);

            return ['ok' => false, 'result' => 'invalid_signature', 'identity' => null];
        }

        $shortCodeResult = null;
        if ($parsed['is_short_code']) {
            [$token, $shortCodeResult] = $this->resolveShortCode($parsed['code']);
        } else {
            $token = $this->resolveCode($parsed['code']);
        }

        $result = $shortCodeResult ?? match (true) {
            $token === null => 'not_found',
            $token->isRevoked() => 'revoked',
            $token->isConsumed() => 'consumed',
            $token->isExpired() => 'expired',
            default => 'valid',
        };

        // Consumo atomico: solo el primer request que llega a marcar
        // consumed_at "gana"; si otro ya lo hizo entre el match() de
        // arriba y este update, aqui se detecta y se corrige el
        // resultado a "consumed".
        if ($result === 'valid' && $token->type === 'dynamic') {
            $updated = QrToken::where('_id', $token->_id)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->update(['consumed_at' => now()]);

            if ($updated === 0) {
                $result = 'consumed';
            }
        }

        $this->logValidation($token, $token?->user_id, $validatedBy, $validatorLabel, $result, $context, $ip);

        if ($result === 'valid') {
            return [
                'ok' => true,
                'result' => 'valid',
                'identity' => $token->user->displayIdentity(),
            ];
        }

        return ['ok' => false, 'result' => $result, 'identity' => null];
    }

    private function resolveCode(string $code): ?QrToken
    {
        $hash = QrLookupHash::code($code);
        $candidates = QrToken::where(function ($query) use ($code, $hash) {
            $query->where('code_hash', $hash)->orWhere('code', $code);
        })->limit(2)->get();

        if ($candidates->count() !== 1) {
            if ($candidates->count() > 1) {
                Log::warning('Ambiguous QR code representation', ['candidate_count' => $candidates->count()]);
            }
            return null;
        }

        $token = $candidates->first();
        $storedCode = $token->getRawOriginal('code');
        $storedHash = $token->getRawOriginal('code_hash');
        $encrypted = $token->getRawOriginal('code_encrypted');

        if (($storedCode !== null && (! is_string($storedCode) || ! hash_equals($storedCode, $code))) ||
            ($storedHash !== null && (! is_string($storedHash) || ! hash_equals($storedHash, $hash))) ||
            ($storedCode === null && $storedHash === null) ||
            ($token->type === 'identification' && $storedHash !== null && $encrypted === null && $storedCode === null) ||
            ($token->type === 'identification' && $encrypted !== null && ! $this->encryptedCodeMatches($encrypted, $code))) {
            $this->recordSecretAnomaly($token, 'lookup_integrity');
            return null;
        }

        return $token;
    }

    private function encryptedCodeMatches(mixed $encrypted, string $code): bool
    {
        if (! is_string($encrypted) || $encrypted === '') {
            return false;
        }

        try {
            return hash_equals(Crypt::decryptString($encrypted), $code);
        } catch (DecryptException) {
            return false;
        }
    }

    private function resolveShortCode(string $shortCode): array
    {
        $shortCodeHash = QrLookupHash::shortCode($shortCode);
        $matching = fn () => QrToken::where(function ($query) use ($shortCode, $shortCodeHash) {
            $query->where('short_code', $shortCode)
                ->orWhere('short_code_hash', $shortCodeHash);
        })->where('type', 'dynamic');

        $usable = $matching()
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->limit(2)->get();

        if ($usable->count() === 1) {
            $candidate = $usable->first();
            if (! $this->shortCodeMatches($candidate, $shortCode, $shortCodeHash)) {
                $this->recordSecretAnomaly($candidate, 'short_lookup_integrity');
                return [null, 'not_found'];
            }
            return [$candidate, null];
        }

        if ($usable->count() > 1) {
            Log::warning('Ambiguous usable QR short code', ['candidate_count' => $usable->count()]);

            return [null, 'not_found'];
        }

        $historical = $matching()->get();
        if ($historical->count() === 1) {
            $candidate = $historical->first();
            if (! $this->shortCodeMatches($candidate, $shortCode, $shortCodeHash)) {
                $this->recordSecretAnomaly($candidate, 'short_lookup_integrity');
                return [null, 'not_found'];
            }
            return [$candidate, null];
        }

        if ($historical->count() > 1) {
            $statuses = $historical->map(fn (QrToken $token) => match (true) {
                $token->isRevoked() => 'revoked',
                $token->isConsumed() => 'consumed',
                default => 'expired',
            })->unique();

            if ($statuses->count() === 1) {
                return [null, $statuses->first()];
            }

            Log::warning('Ambiguous historical QR short code', ['candidate_count' => $historical->count()]);
        }

        return [null, 'not_found'];
    }

    private function shortCodeMatches(QrToken $token, string $code, string $hash): bool
    {
        $storedCode = $token->getRawOriginal('short_code');
        $storedHash = $token->getRawOriginal('short_code_hash');

        return ($storedCode !== null || $storedHash !== null)
            && ($storedCode === null || (is_string($storedCode) && hash_equals($storedCode, $code)))
            && ($storedHash === null || (is_string($storedHash) && hash_equals($storedHash, $hash)));
    }

    private function logValidation(
        ?QrToken $token,
        ?string $userId,
        ?User $validatedBy,
        ?string $validatorLabel,
        string $result,
        ?string $context,
        ?string $ip,
    ): void {
        QrValidation::create([
            'qr_token_id' => $token ? (string) $token->_id : null,
            'user_id' => $userId,
            'validated_by_user_id' => $validatedBy ? (string) $validatedBy->_id : null,
            'validator_label' => $validatorLabel,
            'result' => $result,
            'context' => $context,
            'ip_address' => $ip,
        ]);

        SecurityEvent::log([
            'user_id' => $userId,
            'type' => $result === 'valid' ? 'qr_validated' : 'qr_validation_failed',
            'severity' => $result === 'valid' ? 'info' : 'warning',
            'ip_address' => $ip,
            'metadata' => ['result' => $result, 'context' => $context, 'validator_label' => $validatorLabel],
        ]);
    }

    /**
     * ---------------------------------------------------------------
     * Modulo 1.7 - Dispositivos y sesiones confiables
     * ---------------------------------------------------------------
     */

    /**
     * Huella logica del dispositivo. Se apoya principalmente en una
     * cookie opaca de larga duracion (cd_device_id) para que el mismo
     * telefono/navegador no aparezca como "dispositivo nuevo" cada vez
     * que cambia de IP (redes moviles). Si por algun motivo no hay
     * cookie disponible, cae de vuelta a user agent + IP.
     */
    public function fingerprint(Request $request, ?string $deviceCookie = null): string
    {
        $deviceCookie ??= $request->cookie('cd_device_id');

        $seed = $deviceCookie
            ? 'device:'.$deviceCookie
            : 'ua-ip:'.$request->userAgent().'|'.$request->ip();

        return hash('sha256', $seed);
    }

    /**
     * Resuelve/crea el Device y la UserSession de este request
     * autenticado, ligandola al navegador mediante un token opaco
     * (cd_session_id) guardado en la sesion nativa de Laravel.
     */
    public function trackDeviceSession(Request $request, ?string $deviceCookie = null): UserSession
    {
        $user = $request->user();
        $fingerprint = $this->fingerprint($request, $deviceCookie);

        $device = Device::where('user_id', (string) $user->_id)
            ->where('fingerprint', $fingerprint)
            ->first();

        $isNewDevice = $device === null;

        if (! $device) {
            $device = new Device([
                'user_id' => (string) $user->_id,
                'fingerprint' => $fingerprint,
                'device_name' => $this->guessDeviceName($request),
                'device_type' => 'browser',
                'platform' => $this->guessPlatform($request),
                'browser' => $this->guessBrowser($request),
                'is_trusted' => false,
                'first_seen_at' => now(),
            ]);
        }

        $device->last_seen_at = now();
        $device->last_ip_address = $request->ip();
        $device->save();

        $cdSessionId = $request->session()->get('cd_session_id');
        $session = $cdSessionId ? UserSession::find($cdSessionId) : null;

        if (! $session || $session->isRevoked()) {
            $session = UserSession::create([
                'user_id' => (string) $user->_id,
                'device_id' => (string) $device->_id,
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
                'started_at' => now(),
                'last_activity_at' => now(),
            ]);

            $request->session()->put('cd_session_id', (string) $session->_id);

            SecurityEvent::log([
                'user_id' => (string) $user->_id,
                'device_id' => (string) $device->_id,
                'session_id' => (string) $session->_id,
                'type' => 'login_success',
                'severity' => 'info',
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);

            if ($isNewDevice) {
                SecurityEvent::log([
                    'user_id' => (string) $user->_id,
                    'device_id' => (string) $device->_id,
                    'session_id' => (string) $session->_id,
                    'type' => 'new_device',
                    'severity' => 'warning',
                    'ip_address' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                    'metadata' => ['device_name' => $device->device_name],
                ]);
            }

            $this->enforceConcurrentSessionLimit($user, $session);
        } else {
            $session->last_activity_at = now();
            $session->save();
        }

        return $session;
    }

    /**
     * Limita cuantas sesiones activas puede tener un mismo estudiante
     * a la vez. Si se excede el limite, revoca las sesiones activas
     * mas antiguas (la recien creada nunca se revoca a si misma).
     */
    private function enforceConcurrentSessionLimit(User $user, UserSession $justCreated): void
    {
        $max = (int) env('MAX_ACTIVE_SESSIONS_PER_USER', 5);

        if ($max <= 0) {
            return;
        }

        $active = UserSession::where('user_id', (string) $user->_id)
            ->whereNull('revoked_at')
            ->orderBy('last_activity_at')
            ->get();

        if ($active->count() <= $max) {
            return;
        }

        $excess = $active->count() - $max;

        foreach ($active as $session) {
            if ($excess <= 0) {
                break;
            }

            if ((string) $session->_id === (string) $justCreated->_id) {
                continue;
            }

            $this->revokeSession($user, $session, 'session_limit_exceeded');
            $excess--;
        }
    }

    public function revokeSession(User $user, UserSession $session, string $reason = 'manual'): void
    {
        abort_unless($session->user_id === (string) $user->_id, 403);

        $session->update(['revoked_at' => now(), 'revoked_reason' => $reason]);

        SecurityEvent::log([
            'user_id' => (string) $user->_id,
            'device_id' => $session->device_id,
            'session_id' => (string) $session->_id,
            'type' => 'session_revoked',
            'severity' => 'info',
            'ip_address' => $session->ip_address,
            'metadata' => ['reason' => $reason],
        ]);
    }

    public function revokeSessionById(User $user, string $sessionId, string $reason = 'manual'): void
    {
        $session = UserSession::where('_id', $sessionId)
            ->where('user_id', (string) $user->_id)
            ->first();

        if ($session && ! $session->isRevoked()) {
            $this->revokeSession($user, $session, $reason);
        }
    }

    public function setDeviceTrust(User $user, Device $device, bool $trusted): void
    {
        abort_unless($device->user_id === (string) $user->_id, 403);

        $device->update(['is_trusted' => $trusted]);

        SecurityEvent::log([
            'user_id' => (string) $user->_id,
            'device_id' => (string) $device->_id,
            'type' => $trusted ? 'device_trusted' : 'device_untrusted',
            'severity' => 'info',
        ]);
    }

    /**
     * Elimina un dispositivo del listado del estudiante, revocando
     * primero cualquier sesion activa que dependa de el.
     */
    public function forgetDevice(User $user, Device $device): void
    {
        abort_unless($device->user_id === (string) $user->_id, 403);

        UserSession::where('device_id', (string) $device->_id)
            ->whereNull('revoked_at')
            ->get()
            ->each(fn (UserSession $session) => $this->revokeSession($user, $session, 'device_removed'));

        SecurityEvent::log([
            'user_id' => (string) $user->_id,
            'device_id' => (string) $device->_id,
            'type' => 'device_removed',
            'severity' => 'info',
        ]);

        $device->delete();
    }

    private function guessDeviceName(Request $request): string
    {
        return trim(($this->guessPlatform($request) ?: 'Dispositivo').' · '.($this->guessBrowser($request) ?: 'Navegador'));
    }

    private function guessPlatform(Request $request): string
    {
        $ua = (string) $request->userAgent();

        return match (true) {
            Str::contains($ua, 'Windows') => 'Windows',
            Str::contains($ua, 'Mac OS') => 'macOS',
            Str::contains($ua, 'Android') => 'Android',
            Str::contains($ua, ['iPhone', 'iPad']) => 'iOS',
            Str::contains($ua, 'Linux') => 'Linux',
            default => 'Desconocido',
        };
    }

    private function guessBrowser(Request $request): string
    {
        $ua = (string) $request->userAgent();

        return match (true) {
            Str::contains($ua, 'Edg/') => 'Edge',
            Str::contains($ua, 'Chrome/') => 'Chrome',
            Str::contains($ua, 'Firefox/') => 'Firefox',
            Str::contains($ua, 'Safari/') && ! Str::contains($ua, 'Chrome') => 'Safari',
            default => 'Navegador',
        };
    }
}
