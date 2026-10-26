<?php

namespace App\Domains\Financial\Integrations\Identity;

use App\Domains\Financial\Data\BonusIssuerContext;
use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Exceptions\IdentityFinancialAuthorizationUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;

/** Technical capacity only. This does not establish the human actor's intent. */
final class IdentityFinancialAuthorizationClient
{
    private const SCOPE = 'identity:financial-authorization:check';
    private const PATH = '/api/v1/identity/financial-authorization/check';

    public function check(string $subjectId, BonusType $type, ?BonusIssuerContext $context = null): bool
    {
        $payload = $this->payload($subjectId, $type, $context);
        $origin = $this->origin();
        $token = $this->token($origin);
        try {
            $response = $this->http()->asJson()->withToken($token)->post($origin.self::PATH, $payload);
        } catch (ConnectionException) {
            throw new IdentityFinancialAuthorizationUnavailable('CHECK_NETWORK_FAILURE');
        }
        $this->requireOk($response, 'CHECK');
        $body = $this->json($response, 8192, 'CHECK_INVALID_RESPONSE');
        if (!array_key_exists('authorized', $body) || !is_bool($body['authorized'])) {
            throw new IdentityFinancialAuthorizationUnavailable('CHECK_INVALID_RESPONSE');
        }
        // No local global/context OR: E1 owns that policy and its business validation.
        return $body['authorized'] === true;
    }

    private function payload(string $subjectId, BonusType $type, ?BonusIssuerContext $context): array
    {
        if (!preg_match('/^[a-f0-9]{24}$/Di', $subjectId)) {
            throw new InvalidArgumentException('subject_id debe ser el identificador canonico de Identidad.');
        }
        $scopeType = $context?->scopeType;
        $scopeId = $context?->scopeId;
        if (($scopeType === null) !== ($scopeId === null)) {
            throw new InvalidArgumentException('Ambos campos de contexto deben estar presentes o ser null.');
        }
        if ($scopeType !== null && ($type !== BonusType::NEGOCIO || $scopeType !== 'business')) {
            throw new InvalidArgumentException('Este contrato solo admite contexto business para NEGOCIO; los demas tipos requieren null/null.');
        }
        return ['subject_id' => strtolower($subjectId), 'bonus_type' => $type->value, 'operation' => 'issue',
            'scope_type' => $scopeType, 'scope_id' => $scopeId];
    }

    private function origin(): string
    {
        if (config('financial_identity_authorization.enabled') !== true) {
            throw new IdentityFinancialAuthorizationUnavailable('INTEGRATION_DISABLED');
        }
        $value = config('financial_identity_authorization.origin');
        $origin = is_string($value) ? rtrim($value, '/') : '';
        $parts = parse_url($origin);
        $local = in_array(config('app.env'), ['local', 'testing'], true);
        $loopback = $parts && in_array(strtolower($parts['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], true);
        if (!$parts || !isset($parts['scheme'], $parts['host'])
            || array_diff(array_keys($parts), ['scheme', 'host', 'port']) !== []
            || !($parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && $local && $loopback))) {
            throw new IdentityFinancialAuthorizationUnavailable('INVALID_ORIGIN');
        }
        foreach (['client_id', 'client_secret'] as $key) {
            $credential = config('financial_identity_authorization.'.$key);
            if (!is_string($credential) || trim($credential) === '' || preg_match('/[\x00-\x1f\x7f]/', $credential)) {
                throw new IdentityFinancialAuthorizationUnavailable('MISSING_CREDENTIALS');
            }
        }
        return $origin;
    }

    private function token(string $origin): string
    {
        try {
            $response = $this->http()->asForm()->post($origin.'/api/oauth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => config('financial_identity_authorization.client_id'),
                'client_secret' => config('financial_identity_authorization.client_secret'),
                'scope' => self::SCOPE,
            ]);
        } catch (ConnectionException) {
            throw new IdentityFinancialAuthorizationUnavailable('OAUTH_NETWORK_FAILURE');
        }
        $this->requireOk($response, 'OAUTH');
        $body = $this->json($response, 32768, 'OAUTH_INVALID_RESPONSE');
        if (!is_string($body['access_token'] ?? null) || trim($body['access_token']) === ''
            || preg_match('/[\x00-\x20\x7f]/', $body['access_token'])
            || ($body['token_type'] ?? null) !== 'Bearer'
            || !is_int($body['expires_in'] ?? null) || $body['expires_in'] < 1
            || !is_string($body['scope'] ?? null)
            || !in_array(self::SCOPE, preg_split('/\s+/', trim($body['scope'])), true)) {
            throw new IdentityFinancialAuthorizationUnavailable('OAUTH_INVALID_RESPONSE');
        }
        // No positive-decision cache, persistent token cache, session storage or cross-client reuse.
        return $body['access_token'];
    }

    private function requireOk(Response $response, string $stage): void
    {
        if ($response->status() !== 200) {
            // E1's 401/403 reject the service, not necessarily the human subject's capability.
            throw new IdentityFinancialAuthorizationUnavailable($stage.'_HTTP_'.$response->status(), $response->status());
        }
    }

    private function json(Response $response, int $maxBytes, string $error): array
    {
        $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $raw = $response->body();
        if ($contentType !== 'application/json' || strlen($raw) > $maxBytes) {
            throw new IdentityFinancialAuthorizationUnavailable($error);
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new IdentityFinancialAuthorizationUnavailable($error);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new IdentityFinancialAuthorizationUnavailable($error);
        }
        return $data;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(10);
    }
}
