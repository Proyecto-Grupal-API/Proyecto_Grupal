<?php

namespace App\Http\Middleware;

use App\Services\Directory\DirectoryInput;
use App\Services\Directory\DirectoryPolicy;
use App\Services\Directory\DirectoryQuota;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DirectoryAccess
{
    public function __construct(private AuthorizeIdentityService $grants, private DirectoryQuota $quota) {}

    public function handle(Request $request, Closure $next, string $operation): Response
    {
        $input = DirectoryInput::parse($request->attributes->get('directory_payload'), $operation);
        $client = (string) $request->attributes->get('oauth_client_id', '');
        $tokenScopes = preg_split('/\s+/', trim((string) $request->attributes->get('oauth_scope', '')), -1, PREG_SPLIT_NO_EMPTY);
        $profile = DirectoryPolicy::authorize($client, $tokenScopes, $input, config('identity_directory.clients', []));
        // Reuse E1's persisted active-client/current-grant boundary for EACH required scope.
        foreach (DirectoryPolicy::scopes($input) as $scope) {
            $this->grants->handle($request, fn () => new Response, $scope);
        }
        $this->quota->consume($client, $input, $profile);
        $request->attributes->set('directory_input', $input);

        return $next($request);
    }
}
