<?php
namespace App\Http\Controllers\InventoryModule;
use App\Http\Controllers\Controller;
use App\Services\InventoryModule\BusinessContext;
use App\Services\InventoryModule\IdentityService;
use Illuminate\Http\Request;
class BusinessContextController extends Controller
{
    public function update(Request $request)
    {
        $data=$request->validate(['business_id' => ['required', 'string', 'max:100', 'regex:/^[^\x00-\x20\x7f]+$/']]);
        $context=app(BusinessContext::class);
        abort_unless($context->exists($data['business_id']), 404);
        $context->select($data['business_id']);
        $identity=app(IdentityService::class);
        abort_unless($identity->userCan((string) $request->user()->getAuthIdentifier(), 'dashboard.view'), 403);
        $request->session()->put('inventory_business_id', $data['business_id']);
        if ($identity->isGlobalViewer((string) $request->user()->getAuthIdentifier())) {
            app(\App\Services\InventoryModule\AuditService::class)->log('Inventory.global_viewer_context', ['actor_id' => (string) $request->user()->getAuthIdentifier(),
                'action' => 'consultar_tienda', 'entity_type' => 'business', 'entity_id' => $data['business_id'], 'business_id' => $data['business_id']]);
        }
        return redirect()->route('equipo4.dashboard');
    }
}
