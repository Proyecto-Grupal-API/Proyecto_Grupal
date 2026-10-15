<?php
namespace App\Http\Middleware\InventoryModule;

use App\Exceptions\InventoryModule\IdentityUnavailable;
use App\Services\InventoryModule\BusinessContext;
use App\Services\InventoryModule\IdentityService;
use Closure;
use Illuminate\Http\Request;

class EnsureTeam4Role
{
    public function handle(Request $request, Closure $next)
    {
        $context = app(BusinessContext::class);
        $id = (string) $request->session()->get('inventory_business_id', config('team4.business_id'));
        if (!$context->exists($id)) { abort(404, 'Este negocio no tiene inventario habilitado.'); }
        $context->select($id);
        $identity = app(IdentityService::class);
        try {
            $user = $identity->getUser($identity->resolveCurrentUserId());
            if (!$user || !$identity->userCan($user['id'], 'dashboard.view')) {
                if (!$request->isMethod('GET') || $request->expectsJson()) { abort(403, 'Sin permisos de inventario en este negocio.'); }
                return redirect()->route('equipo4.not-authorized');
            }
            $request->attributes->set('inventory_user', $user);
            $request->attributes->set('team4_user_id', $user['id']);
            $request->attributes->set('team4_user_roles', $user['roles']);
        } catch (IdentityUnavailable $e) { abort(503, 'Identidad no está disponible. Intenta nuevamente más tarde.'); }
        // Request body cannot substitute the actor authenticated by E1.
        if (!$request->isMethod('GET')) { $request->merge(['actor_id' => $user['id']]); }
        return $next($request);
    }
}
