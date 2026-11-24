<?php

namespace App\Http\Middleware;

use App\Services\PlatformModule\PlatformActor;
use Closure;
use Illuminate\Http\Request;

final class ResolvePlatformActor
{
    /** De mayor a menor: si una persona tiene varios cargos, entra con el más amplio. */
    private const PRIORITY = [PlatformActor::ROLE_PLATFORM_ADMIN, PlatformActor::ROLE_ADMIN, PlatformActor::ROLE_AUDITOR,
        PlatformActor::ROLE_PROFESSOR, PlatformActor::ROLE_STUDENT];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless($user, 401);
        $roles = [];
        // Public E1 authorization projection; never read roles/users collections in E7.
        foreach ($user->effectiveRoles() as $assignment) {
            $name = (string) ($assignment['name'] ?? '');
            $type = $assignment['scope_type'] ?? null;
            $id = $assignment['scope_id'] ?? null;
            // Global role, or a role held in one of this module's service contexts. Any other context is ignored.
            $role = empty($type) && empty($id)
                ? config('platform.role_map.'.$name)
                : (is_string($type) && is_string($id) ? (config('platform.context_role_map')[$name][$type][$id] ?? null) : null);
            if (is_string($role)) {
                $roles[] = $role;
            }
        }
        $role = PlatformActor::ROLE_STUDENT;
        foreach (self::PRIORITY as $candidate) {
            if (in_array($candidate, $roles, true)) {
                $role = $candidate;
                break;
            }
        }
        $request->attributes->set('platform_actor', new PlatformActor((string) $user->getKey(), $user->name, $role));

        return $next($request);
    }
}
