<?php

namespace App\Http\Middleware;

use App\Services\PlatformModule\PlatformActor;
use Closure;
use Illuminate\Http\Request;

final class ResolvePlatformActor
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless($user, 401);
        $roles = [];
        // Public E1 authorization projection; never read roles/users collections in E7.
        foreach ($user->effectiveRoles() as $assignment) {
            if (! empty($assignment['scope_type']) || ! empty($assignment['scope_id'])) {
                continue;
            }
            $role = config('platform.role_map.'.($assignment['name'] ?? ''));
            if (is_string($role)) {
                $roles[] = $role;
            }
        }
        $role = in_array('admin', $roles, true) ? 'admin' : (in_array('professor', $roles, true) ? 'professor' : 'student');
        $request->attributes->set('platform_actor', new PlatformActor((string) $user->getKey(), $user->name, $role));

        return $next($request);
    }
}
