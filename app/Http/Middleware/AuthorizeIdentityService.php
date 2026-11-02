<?php

namespace App\Http\Middleware;

use App\Models\ServiceClient;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use MongoDB\Driver\Exception\Exception as MongoException;
use Symfony\Component\HttpFoundation\Response;

/** Revalidate the service's current grants, including tokens issued before a grant was removed. */
class AuthorizeIdentityService
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $clientId = $request->attributes->get('oauth_client_id');
        try {
            $client = is_string($clientId) ? ServiceClient::where('client_id', $clientId)->where('active', true)->first() : null;
        } catch (QueryException|MongoException $exception) {
            if ($scope === 'identity:financial-authorization:check') {
                return response()->json(['message' => 'Authorization infrastructure unavailable.'], 503);
            }
            throw $exception; // Existing commercial error semantics remain unchanged.
        }
        $scopes = $client?->scopes ?? [];
        $scopes = is_array($scopes) ? $scopes : iterator_to_array($scopes);
        abort_unless($client && in_array($scope, $scopes, true), 403);
        if ($scope === 'identity:business-owner:provision') {
            abort_unless(in_array($clientId, config('oauth.business_owner_clients', []), true), 403);
        }

        return $next($request);
    }
}
