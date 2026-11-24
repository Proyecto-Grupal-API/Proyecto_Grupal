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
        if (! $allowed && app(IdentityService::class)->isGlobalViewer((string) $request->user()->getAuthIdentifier())) {
            app(\App\Services\InventoryModule\AuditService::class)->log('Inventory.global_viewer_denied', ['actor_id' => (string) $request->user()->getAuthIdentifier(),
                'action' => $permission, 'entity_type' => 'route', 'entity_id' => $request->path()]);
        }
        abort_unless($allowed, 403, 'No tienes permiso para esta acción en el negocio seleccionado.');
        return $next($request);
    }
}
