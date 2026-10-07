<?php

namespace App\Http\Middleware;

use App\Models\ServiceClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AuthorizeCommunityService
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $id = $request->attributes->get('oauth_client_id');
        $client = is_string($id) ? ServiceClient::where('client_id', $id)->where('active', true)->first() : null;
        if (! $client) {
            throw new UnauthorizedHttpException('Bearer', 'The service client is unavailable or inactive.');
        }
        $scopes = $client->scopes ?? [];
        $scopes = is_array($scopes) ? $scopes : iterator_to_array($scopes);
        abort_unless(in_array($scope, $scopes, true), 403, 'The service client no longer has the required scope.');

        return $next($request);
    }
}
