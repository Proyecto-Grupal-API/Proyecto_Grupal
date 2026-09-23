<?php

namespace App\Http\Middleware;

use App\Services\OAuthTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class ValidateServiceToken
{
    public function __construct(private readonly OAuthTokenService $tokens) {}

    public function handle(Request $request, Closure $next, ?string $requiredScope = null): Response
    {
        $header = $request->header('Authorization', '');
        if (! preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            throw new UnauthorizedHttpException('Bearer', 'A service access token is required.');
        }

        try {
            $claims = $this->tokens->decode($matches[1]);
        } catch (\Throwable) {
            throw new UnauthorizedHttpException('Bearer', 'The service access token is invalid or expired.');
        }

        if (($claims->iss ?? null) !== config('oauth.issuer') || ($claims->aud ?? null) !== config('oauth.audience')) {
            throw new UnauthorizedHttpException('Bearer', 'The service access token has an invalid issuer or audience.');
        }
        if ($requiredScope && ! in_array($requiredScope, preg_split('/\s+/', trim((string) ($claims->scope ?? '')), -1, PREG_SPLIT_NO_EMPTY), true)) {
            abort(403, 'The service token does not have the required scope.');
        }

        $request->attributes->set('oauth_client_id', $claims->sub ?? null);
        $request->attributes->set('oauth_scope', $claims->scope ?? '');
        return $next($request);
    }
}
