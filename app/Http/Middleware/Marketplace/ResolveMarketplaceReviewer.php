<?php

namespace App\Http\Middleware\Marketplace;

use App\Http\Middleware\EnsureHasContextualRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ResolveMarketplaceReviewer
{
    public function handle(Request $request, Closure $next)
    {
        $allowed = false;
        try {
            app(EnsureHasContextualRole::class)->handle($request, function () use (&$allowed) {
                $allowed = true;

                return response('', 204);
            }, 'admin');
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 403) {
                throw $e;
            }
        }
        $request->attributes->set('marketplace_reviewer', $allowed);

        return $next($request);
    }
}
