<?php

namespace App\Services;

use App\Models\ServiceClient;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class OAuthTokenService
{
    public function issue(string $clientId, string $clientSecret, array $requestedScopes = []): array
    {
        $client = ServiceClient::where('client_id', $clientId)->where('active', true)->first();
        if (! $client || ! Hash::check($clientSecret, $client->secret_hash)) {
            throw new UnauthorizedHttpException('Bearer', 'Invalid client credentials.');
        }

        $allowedScopes = $client->scopes ?? [];
        $allowedScopes = is_array($allowedScopes) ? $allowedScopes : iterator_to_array($allowedScopes);

        if (array_diff($requestedScopes, $allowedScopes) !== []) {
            throw new \InvalidArgumentException('One or more requested scopes are not permitted for this client.');
        }

        $scopes = array_values($requestedScopes);
        $now = now()->timestamp;
        $expires = $now + config('oauth.access_token_ttl');
        $claims = [
            'iss' => config('oauth.issuer'),
            'aud' => config('oauth.audience'),
            'sub' => $client->client_id,
            'scope' => implode(' ', $scopes),
            'iat' => $now,
            'exp' => $expires,
            'jti' => (string) Str::uuid(),
        ];

        return [
            'access_token' => JWT::encode($claims, $this->key(), 'HS256'),
            'token_type' => 'Bearer',
            'expires_in' => $expires - $now,
            'scope' => implode(' ', $scopes),
        ];
    }

    public function decode(string $token): object
    {
        return JWT::decode($token, new \Firebase\JWT\Key($this->key(), 'HS256'));
    }

    private function key(): string
    {
        $key = (string) config('oauth.signing_key');
        return str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;
    }
}
