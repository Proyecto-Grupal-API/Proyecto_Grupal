<?php

namespace App\Http\Middleware;

use App\Services\IdentityService;
use App\Services\BusinessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica que el usuario tenga al menos UNO de los roles permitidos.
 *
 * En modo real (Eq.1) un subject puede tener varios roles business.
 * IdentityService::getUser() devuelve 'roles' como array; 'role' es
 * solo el primario para UI.
 */
class EnsureTeam4Role
{
    public function __construct(
        protected IdentityService $identity,
        protected BusinessService $businesses
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $userId = $this->identity->resolveCurrentUserId();
        $user   = $this->identity->getUser($userId);

        if ($user === null) {
            return redirect()->route('equipo4.not-authorized', ['reason' => 'user_not_found']);
        }

        // roles[] es la fuente de verdad. 'role' se mantiene por compat
        // con codigo legacy pero no se usa para autorizar.
        $roles = $user['roles'] ?? [];
        if (!is_array($roles)) {
            $roles = $roles === '' ? [] : [$roles];
        }

        if (!$this->identity->isAuthorized($roles)) {
            return redirect()->route('equipo4.not-authorized', ['reason' => 'role_not_allowed']);
        }

        // Validar scope si es por negocio
        $scope           = $user['role_scope'] ?? 'global';
        $roleBusinessId  = $user['role_business_id'] ?? null;
        $defaultBusiness = (string) config('team4.default_business_id', 'BUS-CD-SOUV-001');

        if ($scope === 'business') {
            if ($roleBusinessId === null) {
                return redirect()->route('equipo4.not-authorized', ['reason' => 'business_scope_missing']);
            }
        }

        $currentBusiness = $scope === 'business' ? (string) $roleBusinessId : $defaultBusiness;
        $request->attributes->set('team4_business_id', $currentBusiness);
        try {
            $this->businesses->validate($currentBusiness);
        } catch (\InvalidArgumentException|\DomainException) {
            return redirect()->route('equipo4.not-authorized', ['reason' => 'business_invalid']);
        }

        config(['team4.business_id' => $currentBusiness]);
        $controller = $request->route()?->getController();
        if ($controller instanceof \App\Http\Controllers\Controller) {
            $controller->setBusinessContext($currentBusiness);
        }

        $request->attributes->set('team4_user_id', $userId);
        $request->attributes->set('team4_user_roles', $roles);
        $request->attributes->set('team4_user_role', $user['role'] ?? '');
        $request->attributes->set('team4_user_scope', $scope);
        return $next($request);
    }
}