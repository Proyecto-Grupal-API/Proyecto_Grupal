<?php

namespace App\Http\Middleware;

use App\Models\ServiceClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Revalidate the service's current grants, including tokens issued before a grant was removed. */
class AuthorizeIdentityService
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $clientId = $request->attributes->get('oauth_client_id');
        $client = is_string($clientId) ? ServiceClient::where('client_id', $clientId)->where('active', true)->first() : null;
        $scopes = $client?->scopes ?? [];
        $scopes = is_array($scopes) ? $scopes : iterator_to_array($scopes);
        abort_unless($client && in_array($scope, $scopes, true), 403);
        if ($scope === 'identity:business-owner:provision') {
            abort_unless(in_array($clientId, config('oauth.business_owner_clients', []), true), 403);
        }

        return $next($request);
    }
}
