<?php
namespace App\Http\Middleware\InventoryModule;
use App\Services\InventoryModule\IdentityService;
use App\Exceptions\InventoryModule\IdentityUnavailable;
use Closure;
use Illuminate\Http\Request;
class EnsureTeam4Permission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        try { $allowed = app(IdentityService::class)->userCan((string) $request->user()->getAuthIdentifier(), $permission); }
        catch (IdentityUnavailable $e) { abort(503, 'Identidad no está disponible.'); }
        abort_unless($allowed, 403, 'No tienes permiso para esta acción en el negocio seleccionado.');
        return $next($request);
    }
}
