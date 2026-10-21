<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Business;
use App\Models\BusinessApplication;
use App\Models\BusinessMember;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\Storefront;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class BusinessManagementController extends Controller
{
    private function userId(Request $request): string { return (string) $request->user()->getKey(); }
    private function isAdmin(Request $request): bool { return (string) ($request->user()->role ?? '') === 'admin'; }

    private function canManage(Request $request, string $businessId, array $roles = ['owner','manager']): bool
    {
        if ($this->isAdmin($request)) return true;
        $business = Business::find($businessId);
        if (!$business) return false;
        if ((string) $business->owner_id === $this->userId($request)) return true;
        return BusinessMember::where('business_id', $businessId)
            ->where('user_id', $this->userId($request))
            ->where('status', 'active')->whereIn('role', $roles)->exists();
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

    // Mismo calculo en centavos que usa MarketplaceController, para que el monto que
    // se le manda a Team2PaymentGateway::refund() sea un entero, no un float.
    private function moneyCents($value): int
    {
        $raw = trim((string) $value);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $raw)) return 0;
        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '0');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public function applications(Request $request)
    {
        $query = BusinessApplication::query()->latest();
        if (!$this->isAdmin($request)) $query->where('applicant_id', $this->userId($request));
        return Inertia::render('Marketplace/Applications', [
            'applications' => $query->paginate(15),
            'isAdmin' => $this->isAdmin($request),
        ]);
    }

    public function submitApplication(Request $request)
    {
        $data = $request->validate([
            'name' => ['required','string','min:3','max:120'],
            'type' => ['required','in:student,association,souvenirs,service,other'],
            'description' => ['required','string','max:1500'],
        ]);
        $application = BusinessApplication::create([
            'business_name' => $data['name'], 'type' => $data['type'], 'description' => $data['description'],
            'applicant_id' => $this->userId($request), 'status' => 'pending',
            'observations' => null, 'submitted_at' => now(),
        ]);
        $this->audit($request, 'application_submitted', 'business_application', (string) $application->getKey(), null, ['business_name' => $data['name']], null, 'pending');
        return back()->with('success', 'Solicitud enviada. Quedó pendiente de revisión.');
    }

    public function reviewApplication(Request $request, string $applicationId)
    {
        abort_unless($this->isAdmin($request), 403, 'Solo administración puede resolver solicitudes.');
        $data = $request->validate([
            'decision' => ['required','in:approved,rejected,observed,suspended'],
            'observations' => ['nullable','string','max:1500'],
        ]);
        $application = BusinessApplication::findOrFail($applicationId);
        abort_if(in_array($application->status, ['approved','rejected','suspended']), 422, 'La solicitud ya tiene una resolución final.');
        $old = $application->status;
        $application->update([
            'status' => $data['decision'], 'observations' => $data['observations'] ?? null,
            'reviewed_at' => now(), 'reviewed_by' => $this->userId($request),
        ]);
        $business = null;
        if ($data['decision'] === 'approved') {
            $slugBase = Str::slug($application->business_name) ?: 'negocio';
            $slug = $slugBase;
            $suffix = 2;
            while (Business::where('slug', $slug)->exists()) $slug = $slugBase.'-'.$suffix++;
            $business = Business::create([
                'name' => $application->business_name, 'slug' => $slug, 'type' => $application->type,
                'owner_id' => (string) $application->applicant_id, 'description' => $application->description,
                'status' => 'approved', 'visibility' => 'public', 'verified' => false,
                'payment_methods' => ['wallet'], 'tags' => [], 'policies' => [], 'hours' => [],
                'delivery_points' => [], 'contact' => [],
            ]);
            BusinessMember::updateOrCreate(
                ['business_id' => (string) $business->getKey(), 'user_id' => (string) $application->applicant_id],
                ['role' => 'owner', 'status' => 'active', 'scopes' => ['business.manage','storefront.manage','catalog.manage','orders.manage','postventa.manage']]
            );
            Storefront::create([
                'business_id' => (string) $business->getKey(), 'name' => $business->name,
                'brand' => $business->name, 'description' => $business->description,
                'published' => false, 'policies' => [], 'hours' => [], 'delivery_points' => [],
                'channels' => [],
            ]);
            $application->update(['business_id' => (string) $business->getKey()]);
            $this->audit($request, 'business_created_from_approval', 'business', (string) $business->getKey(), (string) $business->getKey(), ['application_id' => (string) $application->getKey()], null, 'approved');
        }
        $this->audit($request, 'application_reviewed', 'business_application', (string) $application->getKey(), $business ? (string) $business->getKey() : null, ['observations' => $data['observations'] ?? null], $old, $data['decision']);
        return back()->with('success', $business ? 'Solicitud aprobada y negocio creado; completa la tienda antes de publicarla.' : 'Resolución guardada.');
    }

    public function storeBusiness(Request $request)
    {
        $data = $request->validate([
            'name' => ['required','string','min:3','max:120'],
            'type' => ['required','in:student,association,souvenirs,service,other'],
            'description' => ['required','string','max:1500'],
        ]);
        $application = BusinessApplication::create([
            'business_name' => $data['name'], 'type' => $data['type'],
            'applicant_id' => $this->userId($request), 'description' => $data['description'],
            'status' => 'pending', 'submitted_at' => now(),
        ]);
        $this->audit($request, 'application_submitted', 'business_application', (string)$application->getKey(), null, ['business_name'=>$data['name']], null, 'pending');
        return redirect()->route('marketplace.applications')->with('success', 'Solicitud enviada; el negocio se creará únicamente tras la aprobación administrativa.');
    }

    public function updateBusiness(Request $request, string $businessId)
    {
        abort_unless($this->canManage($request, $businessId), 403);
        $business = Business::findOrFail($businessId);
        $data = $request->validate([
            'name' => ['required','string','min:3','max:120'], 'description' => ['nullable','string','max:1500'],
            'type' => ['required','in:student,association,souvenirs,service,other'],
            'tags' => ['nullable','array','max:20'], 'tags.*' => ['string','max:40'],
            'payment_methods' => ['required','array','min:1'], 'payment_methods.*' => ['in:wallet,bonus,points,mixed,spei,openpay'],
            'visibility' => ['nullable','in:public,private'],
            'contact.email' => ['nullable','email','max:180'], 'contact.phone' => ['nullable','string','max:40'],
            'spei_instructions' => ['nullable','string','max:2000'], 'openpay_profile_id' => ['nullable','string','max:120'],
            'policies.returns' => ['nullable','string','max:1000'], 'policies.privacy' => ['nullable','string','max:1000'],
            'hours' => ['nullable','array'], 'delivery_points' => ['nullable','array','max:30'],
        ]);
        $before = $business->status;
        $business->update($data);
        $this->audit($request, 'business_updated', 'business', $businessId, $businessId, ['fields' => array_keys($data)], $before, $business->status);
        return back()->with('success', 'Configuración del negocio guardada.');
    }

    public function updateBusinessStatus(Request $request, string $businessId)
    {
        abort_unless($this->isAdmin($request), 403, 'Solo administración puede autorizar o suspender negocios.');
        $data = $request->validate(['status' => ['required','in:approved,suspended,retired'], 'reason' => ['required','string','min:3','max:500']]);
        $business = Business::findOrFail($businessId);
        $old = $business->status;
        $business->update(['status' => $data['status'], 'visibility' => $data['status'] === 'approved' ? $business->visibility : 'private', 'verified' => $data['status'] === 'approved']);
        if ($data['status'] !== 'approved') {
            Storefront::where('business_id', $businessId)->update(['published' => false]);
        }
        $this->audit($request, 'business_status_changed', 'business', $businessId, $businessId, ['reason'=>$data['reason']], $old, $data['status']);
        return back()->with('success', 'Estado del negocio actualizado y decisión auditada.');
    }

    public function updateStorefront(Request $request, string $businessId)
    {
        abort_unless($this->canManage($request, $businessId), 403);
        $business = Business::findOrFail($businessId);
        $data = $request->validate([
            'name' => ['required','string','max:120'], 'brand' => ['nullable','string','max:120'],
            'description' => ['nullable','string','max:1500'], 'logo' => ['nullable','url','max:1000'],
            'banner' => ['nullable','url','max:1000'], 'policies' => ['nullable','array'],
            'policies.returns' => ['nullable','string','max:1000'], 'policies.privacy' => ['nullable','string','max:1000'],
            'hours' => ['nullable','array'], 'delivery_points' => ['nullable','array','max:30'],
            'channels' => ['nullable','array'], 'published' => ['required','boolean'],
        ]);
        if ($data['published'] && $business->status !== 'approved') abort(422, 'Solo puede publicarse una tienda autorizada.');
        $store = Storefront::firstOrNew(['business_id' => $businessId]);
        $old = $store->published ? 'published' : 'draft';
        $store->fill($data); $store->business_id = $businessId; $store->save();
        $this->audit($request, 'storefront_updated', 'storefront', (string) $store->getKey(), $businessId, ['published' => $store->published], $old, $store->published ? 'published' : 'draft');
        return back()->with('success', 'Tienda guardada.');
    }

    public function storeProduct(Request $request)
    {
        $data = $request->validate([
            'business_id' => ['required','string'], 'name' => ['required','string','min:2','max:150'],
            'kind' => ['required','in:product,service'], 'category' => ['required','string','max:80'],
            'description' => ['nullable','string','max:2000'], 'price' => ['required','numeric','min:0.01','max:99999999.99'],
            'image' => ['nullable','url','max:1000'], 'currency' => ['required','in:MXN'],
            'active' => ['required','boolean'], 'available' => ['required','boolean'],
            'inventory_required' => ['required','boolean'], 'stock_snapshot' => ['nullable','integer','min:0'],
            'variants' => ['nullable','array'], 'attributes' => ['nullable','array'], 'tags' => ['nullable','array'],
        ]);
        abort_unless($this->canManage($request, $data['business_id'], ['owner','manager','inventory']), 403);
        $business = Business::findOrFail($data['business_id']);
        abort_unless($business->status === 'approved', 422, 'El negocio debe estar autorizado para publicar artículos.');
        $data['slug'] = Str::slug($data['name']).'-'.Str::lower(Str::random(5));
        $product = Product::create($data);
        $this->audit($request, 'product_created', 'product', (string) $product->getKey(), $data['business_id'], ['name' => $product->name], null, $product->active ? 'active' : 'inactive');
        return back()->with('success', 'Producto/servicio creado.');
    }

    public function updateProduct(Request $request, string $productId)
    {
        $product = Product::findOrFail($productId);
        abort_unless($this->canManage($request, (string) $product->business_id, ['owner','manager','inventory']), 403);
        $data = $request->validate([
            'name' => ['required','string','min:2','max:150'], 'kind' => ['required','in:product,service'],
            'category' => ['required','string','max:80'], 'description' => ['nullable','string','max:2000'],
            'price' => ['required','numeric','min:0.01','max:99999999.99'], 'image' => ['nullable','url','max:1000'],
            'currency' => ['required','in:MXN'], 'active' => ['required','boolean'], 'available' => ['required','boolean'],
            'inventory_required' => ['required','boolean'], 'stock_snapshot' => ['nullable','integer','min:0'],
            'variants' => ['nullable','array'], 'attributes' => ['nullable','array'], 'tags' => ['nullable','array'],
        ]);
        $old = $product->active ? 'active' : 'inactive';
        $product->update($data);
        $this->audit($request, 'product_updated', 'product', $productId, (string) $product->business_id, ['fields' => array_keys($data)], $old, $product->active ? 'active' : 'inactive');
        return back()->with('success', 'Producto/servicio actualizado.');
    }

    public function deactivateProduct(Request $request, string $productId)
    {
        $product = Product::findOrFail($productId);
        abort_unless($this->canManage($request, (string) $product->business_id, ['owner','manager','inventory']), 403);
        $product->update(['active' => false, 'available' => false]);
        $this->audit($request, 'product_deactivated', 'product', $productId, (string) $product->business_id, [], 'active', 'inactive');
        return back()->with('success', 'Producto/servicio desactivado.');
    }

    public function saveMember(Request $request, string $businessId)
    {
        abort_unless($this->canManage($request, $businessId, ['owner','manager']), 403);
        $data = $request->validate([
            'user_id' => ['required','string','max:120'],
            'role' => ['required','in:manager,cashier,inventory'],
            'status' => ['required','in:active,inactive'],
        ]);
        $business = Business::findOrFail($businessId);
        User::findOrFail($data['user_id']);
        abort_if((string)$business->owner_id === $data['user_id'] && $data['status'] !== 'active', 422, 'No se puede desactivar al propietario desde esta pantalla.');
        $member = BusinessMember::updateOrCreate(
            ['business_id' => $businessId, 'user_id' => $data['user_id']],
            ['role' => $data['role'], 'status' => $data['status'], 'scopes' => $this->scopesForRole($data['role'])]
        );
        $this->audit($request, 'business_member_updated', 'business_member', (string)$member->getKey(), $businessId, ['user_id'=>$data['user_id'],'role'=>$data['role'],'status'=>$data['status']]);
        return back()->with('success', 'Integrante del negocio guardado.');
    }

    private function scopesForRole(string $role): array
    {
        return match ($role) {
            'manager' => ['storefront.manage','catalog.manage','orders.manage','postventa.manage','payments.review'],
            'cashier' => ['orders.manage','payments.review'],
            'inventory' => ['catalog.manage','inventory.view'],
            default => [],
        };
    }

    public function sellerOrders(Request $request)
    {
        $businessIds = Business::where('owner_id', $this->userId($request))->get()->map(fn($m) => (string) $m->getKey())->all();
        if (!$this->isAdmin($request)) {
            $memberIds = BusinessMember::where('user_id', $this->userId($request))->where('status','active')->whereIn('role',['owner','manager','cashier'])->pluck('business_id')->all();
            $businessIds = array_values(array_unique(array_merge($businessIds, $memberIds)));
        } else {
            $businessIds = Business::get()->map(fn($m) => (string) $m->getKey())->all();
        }
        $orders = Order::whereIn('business_id', $businessIds)->latest()->paginate(20);
        foreach ($orders as $order) {
            $order->payment_intent = PaymentIntent::where('order_id',(string)$order->getKey())->first();
        }
        return Inertia::render('Marketplace/SellerOrders', ['orders' => $orders, 'businessIds' => $businessIds, 'isAdmin' => $this->isAdmin($request)]);
    }

    public function transitionOrder(Request $request, string $orderId, \App\Services\Integrations\Team4InventoryGateway $inventory)
    {
        $order = Order::findOrFail($orderId);
        abort_unless($this->canManage($request, (string) $order->business_id, ['owner','manager','cashier']), 403);
        $data = $request->validate(['status' => ['required','in:preparing,ready,delivered,cancelled'], 'reason' => ['nullable','string','max:500']]);
        $allowed = [
            'confirmed' => ['preparing','cancelled'], 'preparing' => ['ready','cancelled'],
            'ready' => ['delivered','cancelled'],
        ];
        abort_unless(in_array($data['status'], $allowed[$order->status] ?? [], true), 422, 'Transición de pedido no permitida.');
        if ($data['status'] === 'cancelled' && $order->payment_status === 'paid') {
            $order->payment_status = 'refund_pending';
        }
        $releaseResult = null;
        if ($data['status'] === 'cancelled') {
            try {
                $releaseLines = array_values(array_filter($order->items ?? [], fn($line) => ($line['inventory_required'] ?? true) === true));
                if ($releaseLines) $releaseResult = $inventory->release($releaseLines, $orderId);
            } catch (\Throwable $exception) {
                report($exception);
                $releaseResult = ['status' => 'pending_retry'];
            }
        }
        $old = $order->status;
        $history = $order->history ?? [];
        $history[] = ['status' => $data['status'], 'actor_id' => $this->userId($request), 'at' => now()->toISOString(), 'reason' => $data['reason'] ?? null];
        $order->history = $history; $order->status = $data['status']; $order->save();
        $this->audit($request, 'order_status_changed', 'order', $orderId, (string) $order->business_id, ['reason' => $data['reason'] ?? null, 'inventory_release' => $releaseResult], $old, $data['status']);
        return back()->with('success', 'Estado del pedido actualizado.');
    }

    public function requestReturn(Request $request, string $orderId)
    {
        $order = Order::findOrFail($orderId);
        abort_unless((string) $order->buyer_id === $this->userId($request) || $this->isAdmin($request), 403);
        abort_unless(in_array($order->status, ['confirmed','preparing','ready','delivered'], true), 422, 'Solo se puede solicitar postventa cuando el pedido ya fue confirmado.');
        $data = $request->validate(['reason' => ['required','string','min:5','max:1000'], 'evidence' => ['nullable','array','max:5'], 'evidence.*' => ['url','max:1000']]);
        $return = ReturnRequest::create([
            'order_id' => $orderId, 'buyer_id' => (string) $order->buyer_id,
            'business_id' => (string) $order->business_id, 'reason' => $data['reason'],
            'evidence' => $data['evidence'] ?? [], 'status' => 'pending',
            'requested_at' => now(), 'resolved_at' => null,
        ]);
        $this->audit($request, 'return_requested', 'return_request', (string) $return->getKey(), (string) $order->business_id, ['order_id' => $orderId], null, 'pending');
        return back()->with('success', 'Solicitud de postventa registrada.');
    }

    public function resolveReturn(Request $request, string $returnId, \App\Services\Integrations\Team2PaymentGateway $payments)
    {
        $return = ReturnRequest::findOrFail($returnId);
        abort_unless($this->canManage($request, (string) $return->business_id, ['owner','manager']), 403);
        $data = $request->validate(['decision' => ['required','in:approved,rejected'], 'resolution_notes' => ['required','string','min:3','max:1000']]);
        abort_unless($return->status === 'pending', 422, 'La solicitud ya fue resuelta.');
        $old = $return->status;
        $return->update(['status' => $data['decision'], 'resolution_notes' => $data['resolution_notes'], 'resolved_at' => now(), 'resolved_by' => $this->userId($request)]);
        $order = Order::find((string) $return->order_id);
        $refundResult = null;
        if ($data['decision'] === 'approved' && $order) {
            // E3-08: un refund_id propio y unico por devolucion, generado aqui y
            // persistido antes de llamar al adaptador, para no duplicar el reembolso
            // si esta pantalla se vuelve a enviar (doble clic, reintento).
            if ($order->payment_status === 'paid' && !$return->refund_id) {
                $refundId = 'RF-'.strtoupper(substr(md5($returnId.'|'.(string) $order->getKey()), 0, 12));
                $intent = PaymentIntent::where('order_id', (string) $order->getKey())->first();
                try {
                    $refundResult = $payments->refund([
                        'idempotency_key' => $intent->idempotency_key ?? $returnId,
                        'payment_intent_id' => $intent ? (string) $intent->getKey() : null,
                        'refund_id' => $refundId,
                        'amount_cents' => $this->moneyCents($order->total),
                    ], (float) $order->total);
                    $return->update(['refund_id' => $refundId, 'refund_status' => $refundResult['status'] ?? 'requested', 'refund_result' => $refundResult]);
                } catch (\Throwable $exception) {
                    report($exception);
                    $return->update(['refund_id' => $refundId, 'refund_status' => 'pending_retry']);
                }
            }
            $order->update([
                'status' => 'return_approved',
                'payment_status' => $order->payment_status === 'paid' ? 'refund_pending' : $order->payment_status,
                'history' => array_merge($order->history ?? [], [['status'=>'return_approved','actor_id'=>$this->userId($request),'at'=>now()->toISOString(),'reason'=>'return_request_approved']]),
            ]);
        }
        $this->audit($request, 'return_resolved', 'return_request', $returnId, (string) $return->business_id, ['notes' => $data['resolution_notes'], 'refund' => $refundResult], $old, $data['decision']);
        return back()->with('success', $refundResult
            ? 'Resolución de postventa guardada; se solicitó el reembolso al adaptador de pagos.'
            : 'Resolución de postventa guardada. El reembolso/inventario requiere confirmar la integración propietaria.');
    }

    public function submitDirectPaymentEvidence(Request $request, string $orderId)
    {
        $order = Order::findOrFail($orderId);
        abort_unless((string) $order->buyer_id === $this->userId($request), 403);
        abort_unless(in_array($order->payment_method, ['spei','openpay'], true), 422, 'El pedido no usa un método directo.');
        abort_unless(in_array($order->payment_status, ['pending','pending_validation'], true), 422, 'El pago ya no admite comprobantes.');
        $data = $request->validate(['evidence_url' => ['required','url','max:1000'], 'reference' => ['nullable','string','max:120']]);
        $intent = PaymentIntent::where('order_id', $orderId)->first();
        if ($intent) $intent->update(['status' => 'pending_validation', 'evidence' => ['url' => $data['evidence_url'], 'reference' => $data['reference'] ?? null, 'submitted_at' => now()->toISOString()]]);
        $this->audit($request, 'direct_payment_evidence_submitted', 'order', $orderId, (string) $order->business_id, ['reference' => $data['reference'] ?? null]);
        return back()->with('success', 'Comprobante enviado para revisión; el pago sigue pendiente.');
    }

    public function resolveDirectPayment(Request $request, string $orderId, \App\Services\Integrations\Team4InventoryGateway $inventory)
    {
        abort_unless($this->isAdmin($request), 403, 'Solo administración puede validar pagos directos.');
        $order = Order::findOrFail($orderId);
        abort_unless(in_array($order->payment_method, ['spei','openpay'], true), 422);
        $data = $request->validate(['decision' => ['required','in:approved,rejected'], 'notes' => ['required','string','min:3','max:500']]);
        abort_unless(in_array($order->payment_status, ['pending','pending_validation'], true), 422, 'El pago ya fue resuelto.');
        $intentForReview = PaymentIntent::where('order_id', $orderId)->first();
        if ($data['decision'] === 'approved') {
            abort_unless(is_array($intentForReview?->evidence) && !empty($intentForReview->evidence['url']), 422, 'Antes de aprobar, el comprador debe adjuntar el comprobante.');
        }
        $old = $order->payment_status;
        $newStatus = $data['decision'] === 'approved' ? 'confirmed' : 'payment_rejected';
        $order->update([
            'payment_status' => $data['decision'] === 'approved' ? 'paid' : 'failed',
            'status' => $newStatus,
            'history' => array_merge($order->history ?? [], [['status'=>$newStatus,'actor_id'=>$this->userId($request),'at'=>now()->toISOString(),'reason'=>'manual_payment_review: '.$data['decision']]]),
        ]);
        $intent = PaymentIntent::where('order_id', $orderId)->first();
        if ($intent) $intent->update(['status' => $data['decision'] === 'approved' ? 'manually_authorized' : 'rejected', 'metadata' => array_merge($intent->metadata ?? [], ['review_notes' => $data['notes'], 'reviewed_by' => $this->userId($request), 'reviewed_at' => now()->toISOString()])]);
        $inventoryOutcome = null;
        $inventoryLines = array_values(array_filter($order->items ?? [], fn($line) => ($line['inventory_required'] ?? true) === true));
        if ($inventoryLines) {
            try {
                // El checkout de un metodo directo (SPEI/Openpay) deja el inventario solo
                // "reserved"; aqui es donde se confirma de verdad (pago aprobado) o se
                // libera (pago rechazado) -antes esta rama no tocaba el inventario y la
                // reserva se quedaba "reserved" para siempre.
                $inventoryOutcome = $data['decision'] === 'approved'
                    ? $inventory->confirm($inventoryLines, $orderId, $intent ? (string) $intent->getKey() : null)
                    : $inventory->release($inventoryLines, $orderId);
            } catch (\Throwable $exception) { report($exception); $inventoryOutcome = ['status' => 'pending_retry']; }
        }
        $this->audit($request, 'direct_payment_resolved', 'order', $orderId, (string) $order->business_id, ['notes' => $data['notes'], 'inventory' => $inventoryOutcome], $old, $order->payment_status);
        return back()->with('success', 'Resolución manual guardada con auditoría.');
    }

    public function sellerDashboard(Request $request)
    {
        $owned = Business::where('owner_id', $this->userId($request))->get()->map(fn($m) => (string) $m->getKey())->all();
        $member = BusinessMember::where('user_id', $this->userId($request))->where('status','active')->whereIn('role',['owner','manager','cashier'])->pluck('business_id')->all();
        $ids = $this->isAdmin($request) ? Business::get()->map(fn($m) => (string) $m->getKey())->all() : array_values(array_unique(array_merge($owned,$member)));
        $orders = Order::whereIn('business_id', $ids)->get();
        $sales = $orders->where('payment_status','paid');
        return Inertia::render('Marketplace/SellerDashboard', [
            'businesses' => Business::whereIn('_id', $ids)->get(),
            'stats' => ['orders' => $orders->count(), 'paid_sales' => round($sales->sum(fn($o)=>(float)$o->total),2), 'pending' => $orders->whereIn('status',['pending_payment','awaiting_payment_validation'])->count(), 'cancelled' => $orders->where('status','cancelled')->count()],
            'topProducts' => $orders->flatMap(fn($o)=>$o->items ?? [])->groupBy('product_id')->map(fn($rows)=>['name'=>$rows->first()['name'] ?? 'Artículo','units'=>$rows->sum('quantity')])->sortByDesc('units')->take(5)->values(),
        ]);
    }

    public function auditLog(Request $request)
    {
        abort_unless($this->isAdmin($request), 403);
        return Inertia::render('Marketplace/AuditLog', ['events' => AuditEvent::latest('occurred_at')->paginate(30)]);
    }
}
