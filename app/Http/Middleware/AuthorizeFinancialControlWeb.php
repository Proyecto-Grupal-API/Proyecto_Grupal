<?php

namespace App\Http\Middleware;

use App\Domains\Financial\Contracts\FinancialWebAuthorizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeFinancialControlWeb
{
    public function __construct(private readonly FinancialWebAuthorizer $authorizer) {}

    public function handle(Request $request, Closure $next, string $action): Response
    {
        $user = $request->user();
        abort_unless($user, 401);
        // These endpoints expose institution-wide controls: require a global grant.
        abort_unless($this->authorizer->allows((string) $user->getKey(), $action, null), 403);
        return $next($request);
    }
}
