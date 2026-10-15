<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\AuditEvent;
use App\Models\Marketplace\Business;
use App\Models\Marketplace\BusinessApplication;
use App\Models\Marketplace\Order;
use App\Models\Marketplace\PaymentIntent;
use App\Models\Marketplace\Product;
use App\Models\Marketplace\ReturnRequest;
use App\Models\Marketplace\Storefront;
use App\Services\Marketplace\MarketplaceIntegration;
use App\Services\Marketplace\MarketplaceOrders;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BusinessManagementController extends Controller
{
    public function __construct(private MarketplaceIntegration $integration) {}

    private function userId(Request $request): string
    {
        return (string) $request->user()->getKey();
    }

    private function isAdmin(Request $request): bool
    {
        return (bool) $request->attributes->get('marketplace_reviewer');
    }

    private function canManage(Request $request, string $businessId, array $roles = ['owner', 'manager']): bool
    {
        $capability = in_array('inventory', $roles, true) ? 'business.inventory.operate' : (in_array('cashier', $roles, true) ? 'business.sales.operate' : 'business.operate');

        return $this->integration->allowed($this->userId($request), $businessId, $capability);
    }

    private function audit(Request $request, string $action, string $type, string $id, ?string $businessId = null, array $details = [], ?string $from = null, ?string $to = null): void
    {
        AuditEvent::create([
            'actor_id' => $this->userId($request),
            'actor_name' => $request->user()->name ?? $request->user()->email,
            'business_id' => $businessId,
            'entity_type' => $type, 'entity_id' => $id, 'action' => $action,
            'from_status' => $from, 'to_status' => $to, 'details' => $details, 'occurred_at' => now(),
        ]);
    }

    public function applications(Request $request)
    {
        $query = BusinessApplication::query()->latest();
        if (! $this->isAdmin($request)) {
            $query->where('applicant_id', $this->userId($request));
        }

        return Inertia::render('Marketplace/Applications', [
            'applications' => $query->paginate(15),
            'isAdmin' => $this->isAdmin($request), 'organizations' => $this->integration->organizations($this->userId($request)),
        ]);
    }

    public function submitApplication(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'type' => ['required', 'in:student,association,souvenirs,service,other'],
            'description' => ['required', 'string', 'max:1500'],
            'organization_id' => ['required_if:type,association', 'nullable', 'string', 'max:100'],
        ]);
        if ($data['type'] === 'association') {
            $this->integration->requireOrganization($this->userId($request), $data['organization_id']);
        }
        $application = BusinessApplication::create([
            'organization_id' => $data['type'] === 'association' ? $data['organization_id'] : null,
            'business_name' => $data['name'], 'type' => $data['type'], 'description' => $data['description'],
            'applicant_id' => $this->userId($request), 'status' => 'pending',
            'observations' => null, 'submitted_at' => now(),
        ]);
        $this->audit($request, 'application_submitted', 'business_application', (string) $application->getKey(), null, ['business_name' => $data['name']], null, 'pending');

        return back()->with('success', 'Solicitud enviada. Quedó pendiente de revisión.');
    }

    public function reviewApplication(Request $request, string $applicationId)
    {
        abort_unless($this->isAdmin($request), 403, 'Solo la administración institucional puede revisar solicitudes.');
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected,observed,suspended'], 'observations' => ['nullable', 'string', 'max:1500']]);

        return MarketplaceOrders::locked('application:'.$applicationId, function () use ($request, $applicationId, $data) {
            $application = BusinessApplication::findOrFail($applicationId);
            if ($application->status === 'approved' && $data['decision'] === 'approved') {
                return back()->with('success', 'La solicitud ya fue aprobada.');
            }
            abort_unless(in_array($application->status, ['pending', 'observed', 'provisioning'], true), 422, 'La solicitud ya tiene una resolución final.');
            $old = $application->status;
            if ($data['decision'] === 'approved') {
                if ($application->type === 'association') {
                    $this->integration->requireOrganization((string) $application->applicant_id, (string) $application->organization_id);
                }
                $id = 'BUS-E3-'.$application->getKey();
                $business = Business::firstOrCreate(['_id' => $id], ['name' => $application->business_name, 'slug' => Str::slug($application->business_name).'-'.$application->getKey(),
                    'type' => $application->type, 'owner_id' => (string) $application->applicant_id, 'organization_id' => $application->organization_id, 'description' => $application->description,
                    'status' => 'provisioning', 'visibility' => 'private', 'verified' => false, 'inventory_ready' => false, 'payment_methods' => ['wallet'], 'tags' => []]);
                $application->update(['business_id' => $id, 'status' => 'provisioning', 'integration_error' => null]);
                Storefront::firstOrCreate(['business_id' => $id], ['name' => $business->name, 'brand' => $business->name, 'description' => $business->description, 'published' => false]);
                try {
                    $this->integration->provisionOwner((string) $application->applicant_id, $id, 'marketplace-owner:'.$applicationId);
                    $result = $this->integration->inventory($id, 'POST', 'register', ['business_id' => $id, 'name' => $business->name]);
                    abort_unless(($result['business_id'] ?? null) === $id && ($result['active'] ?? false) === true, 503, 'Inventarios no confirmó el negocio.');
                } catch (HttpException $e) {
                    $application->update(['integration_error' => 'No se confirmó Identidad o Inventarios. Reintenta la aprobación; se conservarán la solicitud y el mismo propietario.']);
                    throw $e;
                }
                $business->update(['status' => 'approved', 'visibility' => 'public', 'verified' => true, 'inventory_ready' => true]);
            } else {
                abort_if($application->status === 'provisioning', 409, 'La asignación institucional está en curso; termina la aprobación antes de cambiar la resolución.');
            }
            $application->update(['status' => $data['decision'], 'observations' => $data['observations'] ?? null, 'reviewed_at' => now(), 'reviewed_by' => $this->userId($request), 'integration_error' => null]);
            $this->audit($request, 'application_reviewed', 'business_application', $applicationId, $application->business_id, ['decision' => $data['decision']], $old, $data['decision']);

            return back()->with('success', 'Resolución registrada. La tienda aprobada puede configurarse y publicarse por su personal autorizado.');
        });
    }

    public function storeBusiness(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'type' => ['required', 'in:student,association,souvenirs,service,other'],
            'description' => ['required', 'string', 'max:1500'],
            'organization_id' => ['required_if:type,association', 'nullable', 'string', 'max:100'],
        ]);
        if ($data['type'] === 'association') {
            $this->integration->requireOrganization($this->userId($request), $data['organization_id']);
        }
        $application = BusinessApplication::create([
            'organization_id' => $data['type'] === 'association' ? $data['organization_id'] : null,
            'business_name' => $data['name'], 'type' => $data['type'],
            'applicant_id' => $this->userId($request), 'description' => $data['description'],
            'status' => 'pending', 'submitted_at' => now(),
        ]);
        $this->audit($request, 'application_submitted', 'business_application', (string) $application->getKey(), null, ['business_name' => $data['name']], null, 'pending');

        return redirect()->route('marketplace.applications')->with('success', 'Solicitud enviada; el negocio se creará únicamente tras la aprobación administrativa.');
    }

    public function updateBusiness(Request $request, string $businessId)
    {
        abort_unless($this->integration->allowed($this->userId($request), $businessId, 'business.manage'), 403);
        $business = Business::findOrFail($businessId);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'], 'description' => ['nullable', 'string', 'max:1500'],
            'type' => ['required', 'in:student,association,souvenirs,service,other'],
            'tags' => ['nullable', 'array', 'max:20'], 'tags.*' => ['string', 'max:40'],
            'payment_methods' => ['required', 'array', 'min:1'], 'payment_methods.*' => ['in:wallet,bonus,points,mixed,spei,openpay'],
            'visibility' => ['nullable', 'in:public,private'],
            'contact.email' => ['nullable', 'email', 'max:180'], 'contact.phone' => ['nullable', 'string', 'max:40'],
            'spei_instructions' => ['nullable', 'string', 'max:2000'], 'openpay_profile_id' => ['nullable', 'string', 'max:120'],
            'policies.returns' => ['nullable', 'string', 'max:1000'], 'policies.privacy' => ['nullable', 'string', 'max:1000'],
            'hours' => ['nullable', 'array'], 'delivery_points' => ['nullable', 'array', 'max:30'],
        ]);
        abort_unless($data['type'] === $business->type, 422, 'El tipo de negocio se define en su solicitud aprobada.');
        $before = $business->status;
        $business->update($data);
        $this->audit($request, 'business_updated', 'business', $businessId, $businessId, ['fields' => array_keys($data)], $before, $business->status);

        return back()->with('success', 'Configuración del negocio guardada.');
    }

    public function updateBusinessStatus(Request $request, string $businessId)
    {
        abort_unless($this->isAdmin($request), 403, 'Solo administración puede autorizar o suspender negocios.');
        $data = $request->validate(['status' => ['required', 'in:approved,suspended,retired'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);
        $business = Business::findOrFail($businessId);
        if ($data['status'] === 'approved') {
            abort_unless($business->inventory_ready && $this->integration->allowed((string) $business->owner_id, $businessId, 'business.manage', true), 409, 'Primero debe confirmarse la aprobación original y su propietario vigente.');
        }
        $old = $business->status;
        $business->update(['status' => $data['status'], 'visibility' => $data['status'] === 'approved' ? $business->visibility : 'private', 'verified' => $data['status'] === 'approved']);
        if ($data['status'] !== 'approved') {
            Storefront::where('business_id', $businessId)->update(['published' => false]);
        }
        $this->audit($request, 'business_status_changed', 'business', $businessId, $businessId, ['reason' => $data['reason']], $old, $data['status']);

        return back()->with('success', 'Estado del negocio actualizado y decisión auditada.');
    }

    public function updateStorefront(Request $request, string $businessId)
    {
        abort_unless($this->canManage($request, $businessId), 403);
        $business = Business::findOrFail($businessId);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'brand' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1500'], 'logo' => ['nullable', 'url', 'max:1000'],
            'banner' => ['nullable', 'url', 'max:1000'], 'policies' => ['nullable', 'array'],
            'policies.returns' => ['nullable', 'string', 'max:1000'], 'policies.privacy' => ['nullable', 'string', 'max:1000'],
            'hours' => ['nullable', 'array'], 'delivery_points' => ['nullable', 'array', 'max:30'],
            'channels' => ['nullable', 'array'], 'published' => ['required', 'boolean'],
        ]);
        if ($data['published'] && $business->status !== 'approved') {
            abort(422, 'Solo puede publicarse una tienda autorizada.');
        }
        $store = Storefront::firstOrNew(['business_id' => $businessId]);
        $old = $store->published ? 'published' : 'draft';
        $store->fill($data);
        $store->business_id = $businessId;
        $store->save();
        $this->audit($request, 'storefront_updated', 'storefront', (string) $store->getKey(), $businessId, ['published' => $store->published], $old, $store->published ? 'published' : 'draft');

        return back()->with('success', 'Tienda guardada.');
    }

    public function storeProduct(Request $request)
    {
        $data = $request->validate([
            'business_id' => ['required', 'string'], 'name' => ['required', 'string', 'min:2', 'max:150'],
            'kind' => ['required', 'in:product,service'], 'category' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'], 'price' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'image' => ['nullable', 'url', 'max:1000'], 'currency' => ['required', 'in:MXN'],
            'active' => ['required', 'boolean'], 'available' => ['required', 'boolean'],
            'inventory_required' => ['required', 'boolean'], 'inventory_product_id' => ['required_if:inventory_required,true', 'nullable', 'string', 'max:100'], 'inventory_location_id' => ['required_if:inventory_required,true', 'nullable', 'string', 'max:100'],
            'variants' => ['nullable', 'array'], 'attributes' => ['nullable', 'array'], 'tags' => ['nullable', 'array'],
        ]);
        abort_unless($this->canManage($request, $data['business_id'], ['owner', 'manager', 'inventory']), 403);
        $business = Business::findOrFail($data['business_id']);
        abort_unless($business->status === 'approved', 422, 'El negocio debe estar autorizado para publicar artículos.');
        $this->integration->validateInventory($data['business_id'], $data);
        $data['slug'] = Str::slug($data['name']).'-'.Str::lower(Str::random(5));
        $product = Product::create($data);
        $this->audit($request, 'product_created', 'product', (string) $product->getKey(), $data['business_id'], ['name' => $product->name], null, $product->active ? 'active' : 'inactive');

        return back()->with('success', 'Producto/servicio creado.');
    }

    public function updateProduct(Request $request, string $productId)
    {
        $product = Product::findOrFail($productId);
        abort_unless($this->canManage($request, (string) $product->business_id, ['owner', 'manager', 'inventory']), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'], 'category' => ['required', 'string', 'max:80'],
            'kind' => ['required', 'in:product,service'], 'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'], 'image' => ['nullable', 'url', 'max:1000'],
            'active' => ['required', 'boolean'], 'available' => ['required', 'boolean'],
            'inventory_required' => ['required', 'boolean'], 'inventory_product_id' => ['required_if:inventory_required,true', 'nullable', 'string', 'max:100'], 'inventory_location_id' => ['required_if:inventory_required,true', 'nullable', 'string', 'max:100'],
            'variants' => ['nullable', 'array'], 'attributes' => ['nullable', 'array'], 'tags' => ['nullable', 'array'],
        ]);
        $this->integration->validateInventory((string) $product->business_id, $data);
        $product->update($data);
        $this->audit($request, 'product_updated', 'product', $productId, (string) $product->business_id, ['fields' => array_keys($data)]);

        return back()->with('success', 'Producto actualizado.');
    }

    public function deactivateProduct(Request $request, string $productId)
    {
        $product = Product::findOrFail($productId);
        abort_unless($this->canManage($request, (string) $product->business_id, ['owner', 'manager', 'inventory']), 403);
        $product->update(['active' => false, 'available' => false]);
        $this->audit($request, 'product_deactivated', 'product', $productId, (string) $product->business_id, [], 'active', 'inactive');

        return back()->with('success', 'Producto desactivado; se conserva su historial.');
    }

    public function saveMember(Request $request, string $businessId)
    {
        abort_unless($this->integration->allowed($this->userId($request), $businessId, 'business.members.manage'), 403);
        abort(503, 'Las asignaciones de personal pertenecen a Identidad. Su API de cambios todavía no está integrada.');
    }

    public function sellerOrders(Request $request)
    {
        $ids = $this->integration->businesses($this->userId($request), 'business.sales.operate');
        $orders = Order::whereIn('business_id', $ids)->latest()->paginate(20);
        foreach ($orders as $order) {
            $order->payment_intent = PaymentIntent::where('order_id', (string) $order->getKey())->first();
        }

        return Inertia::render('Marketplace/SellerOrders', ['orders' => $orders, 'businessIds' => $ids, 'isAdmin' => false]);
    }

    public function transitionOrder(Request $request, string $orderId, MarketplaceOrders $orders)
    {
        $order = Order::findOrFail($orderId);
        $data = $request->validate(['status' => ['required', 'in:preparing,ready,delivered,cancelled'], 'reason' => ['nullable', 'string', 'max:500']]);
        $buyer = $order->buyer_id === $this->userId($request);
        abort_unless(($buyer && $data['status'] === 'cancelled') || $this->canManage($request, $order->business_id, ['owner', 'manager', 'cashier']), 403);
        if ($data['status'] === 'cancelled') {
            $orders->cancel($order, $this->userId($request), $data['reason'] ?? '');
        } else {
            MarketplaceOrders::locked('order:'.$orderId, function () use ($request, $order, $data) {
                $order->refresh();
                abort_unless($order->payment_status === 'paid', 422, 'El pago aún no fue confirmado por Finanzas.');
                $transitions = ['confirmed' => 'preparing', 'preparing' => 'ready', 'ready' => 'delivered'];
                abort_unless(($transitions[$order->status] ?? null) === $data['status'], 422, 'Transición no permitida.');
                $order->update(['status' => $data['status'], 'history' => array_merge($order->history ?? [], [['status' => $data['status'], 'actor_id' => $this->userId($request), 'at' => now()->toISOString()]])]);
            });
        }
        $this->audit($request, 'order_status_changed', 'order', $orderId, $order->business_id, ['status' => $data['status']]);

        return back()->with('success', 'Pedido actualizado. Las liberaciones pendientes se volverán a consultar automáticamente.');
    }

    public function requestReturn(Request $request, string $orderId)
    {
        $order = Order::findOrFail($orderId);
        abort_unless((string) $order->buyer_id === $this->userId($request), 403);
        abort_unless($order->payment_status === 'paid' && in_array($order->status, ['confirmed', 'preparing', 'ready', 'delivered'], true), 422, 'Solo se puede solicitar postventa cuando el pedido ya fue confirmado.');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000'], 'evidence' => ['nullable', 'array', 'max:5'], 'evidence.*' => ['url', 'max:1000']]);
        $return = ReturnRequest::create([
            'order_id' => $orderId, 'buyer_id' => (string) $order->buyer_id,
            'business_id' => (string) $order->business_id, 'reason' => $data['reason'],
            'evidence' => $data['evidence'] ?? [], 'status' => 'pending',
            'requested_at' => now(), 'resolved_at' => null,
        ]);
        $this->audit($request, 'return_requested', 'return_request', (string) $return->getKey(), (string) $order->business_id, ['order_id' => $orderId], null, 'pending');

        return back()->with('success', 'Solicitud de postventa registrada.');
    }

    public function resolveReturn(Request $request, string $returnId)
    {
        $return = ReturnRequest::findOrFail($returnId);
        abort_unless($this->canManage($request, (string) $return->business_id, ['owner', 'manager']), 403);
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected'], 'resolution_notes' => ['required', 'string', 'min:3', 'max:1000']]);
        abort_unless($return->status === 'pending', 422, 'La solicitud ya fue resuelta.');
        $old = $return->status;
        $return->update(['status' => $data['decision'], 'resolution_notes' => $data['resolution_notes'], 'resolved_at' => now(), 'resolved_by' => $this->userId($request)]);
        $order = Order::find((string) $return->order_id);
        if ($data['decision'] === 'approved' && $order) {
            $order->update([
                'status' => 'return_approved',
                'payment_status' => $order->payment_status === 'paid' ? 'refund_pending' : $order->payment_status,
                'history' => array_merge($order->history ?? [], [['status' => 'return_approved', 'actor_id' => $this->userId($request), 'at' => now()->toISOString(), 'reason' => 'return_request_approved']]),
            ]);
        }
        $this->audit($request, 'return_resolved', 'return_request', $returnId, (string) $return->business_id, ['notes' => $data['resolution_notes']], $old, $data['decision']);

        return back()->with('success', 'Resolución de postventa guardada. El reembolso/inventario requiere confirmar la integración propietaria.');
    }

    public function submitDirectPaymentEvidence(Request $request, string $orderId)
    {
        $order = Order::findOrFail($orderId);
        abort_unless((string) $order->buyer_id === $this->userId($request), 403);
        abort_unless(in_array($order->payment_method, ['spei', 'openpay'], true), 422, 'El pedido no usa un método directo.');
        abort_unless($order->status === 'awaiting_payment' && ! $order->hold_expires_at?->isPast() && in_array($order->payment_status, ['pending', 'pending_validation'], true), 422, 'El pedido ya no admite comprobantes.');
        $data = $request->validate(['evidence_url' => ['required', 'url', 'max:1000'], 'reference' => ['nullable', 'string', 'max:120']]);
        $intent = PaymentIntent::where('order_id', $orderId)->first();
        if ($intent) {
            $intent->update(['status' => 'pending_validation', 'evidence' => ['url' => $data['evidence_url'], 'reference' => $data['reference'] ?? null, 'submitted_at' => now()->toISOString()]]);
        }
        $this->audit($request, 'direct_payment_evidence_submitted', 'order', $orderId, (string) $order->business_id, ['reference' => $data['reference'] ?? null]);

        return back()->with('success', 'Comprobante enviado para revisión; el pago sigue pendiente.');
    }

    public function resolveDirectPayment(Request $request, string $orderId)
    {
        $order = Order::findOrFail($orderId);
        abort_unless($this->canManage($request, $order->business_id, ['owner', 'manager', 'cashier']), 403);
        abort(503, 'La confirmación de cobros requiere la integración de Finanzas. Un comprobante no autoriza marcar el pedido como pagado.');
    }

    public function sellerDashboard(Request $request)
    {
        $ids = $this->integration->businesses($this->userId($request), 'business.sales.operate');
        $orders = Order::whereIn('business_id', $ids)->get();

        return Inertia::render('Marketplace/SellerDashboard', ['businesses' => Business::whereIn('_id', $ids)->get(),
            'stats' => ['orders' => $orders->count(), 'paid_sales' => $orders->where('payment_status', 'paid')->sum('total_cents') / 100, 'pending' => $orders->whereIn('status', ['pending_inventory', 'awaiting_payment'])->count(), 'cancelled' => $orders->where('status', 'cancelled')->count()],
            'topProducts' => $orders->where('payment_status', 'paid')->flatMap(fn ($o) => $o->items ?? [])->groupBy('product_id')->map(fn ($rows) => ['name' => $rows->first()['name'], 'units' => $rows->sum('quantity')])->values()->take(5)]);
    }

    public function auditLog(Request $request)
    {
        $ids = $this->integration->businesses($this->userId($request), 'business.manage');
        $reviewer = $this->isAdmin($request);
        $query = AuditEvent::where(function ($q) use ($ids,$reviewer) {
            $q->whereIn('business_id',$ids);
            if ($reviewer) {
                $q->orWhereIn('entity_type',['business_application', 'business']);
            }
        });

        return Inertia::render('Marketplace/AuditLog',['events' => $query->latest('occurred_at')->paginate(30)]);
    }
}
