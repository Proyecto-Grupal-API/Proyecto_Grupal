<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Models\Product;
use App\Services\Integrations\Team2PaymentGateway;
use App\Services\Integrations\Team4InventoryGateway;
use App\Services\Integrations\Team7RewardsGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class MarketplaceController extends Controller
{
    public function index(Request $request)
    {
        $publishedBusinessIds = \App\Models\Storefront::where('published', true)->pluck('business_id')->map(fn($id)=>(string)$id)->all();
        $businesses = Business::where('status', 'approved')->where('visibility', 'public')
            ->whereIn('_id', $publishedBusinessIds)->limit(12)->get();

        $publicBusinessIds = Business::where('status','approved')->where('visibility','public')->whereIn('_id', $publishedBusinessIds)->pluck('_id')->map(fn($id)=>(string)$id)->all();
        $products = Product::where('active', true)->where('available', true)->whereIn('business_id', $publicBusinessIds)
            ->limit(24)->get();

        $stats = [
            'businesses' => Business::where('status', 'approved')->count(),
            'products' => Product::where('active', true)->count(),
            'orders' => Order::count(),
            'sales' => (float) Order::where('payment_status', 'paid')->sum('total'),
        ];
        $cart = Cart::where('user_id', (string) $request->user()->getKey())->first();
        $cartBusiness = null;
        if ($cart && !empty($cart->items[0]['business_id'])) {
            $candidate = Business::find((string)$cart->items[0]['business_id']);
            if ($candidate && $candidate->status === 'approved' && $candidate->visibility === 'public') $cartBusiness = $candidate;
        }

        return Inertia::render('Marketplace/Index', [
            'businesses' => $businesses,
            'products' => $products,
            'stats' => $stats,
            'cart' => $cart?->toArray(),
            'cartBusiness' => $cartBusiness,
            'isAdmin' => (string)($request->user()->role ?? '') === 'admin',
        ]);
    }

    public function businesses(Request $request)
    {
        $userId = (string) $request->user()->getKey();
        $query = Business::query();
        if ((string)($request->user()->role ?? '') !== 'admin') {
            $owned = Business::where('owner_id', $userId)->pluck('_id')->map(fn($id)=>(string)$id)->all();
            $member = \App\Models\BusinessMember::where('user_id', $userId)->where('status','active')->pluck('business_id')->all();
            $ids = array_values(array_unique(array_merge($owned, $member)));
            $query->where(function($q) use ($ids) { $q->where(function($public) { $public->where('status','approved')->where('visibility','public'); })->orWhereIn('_id',$ids); });
        }
        $managedIds = Business::where('owner_id',$userId)->pluck('_id')->map(fn($id)=>(string)$id)->all();
        $managedMemberIds = \App\Models\BusinessMember::where('user_id',$userId)->where('status','active')->whereIn('role',['owner','manager','inventory'])->pluck('business_id')->all();
        $managedIds = array_values(array_unique(array_merge($managedIds,$managedMemberIds)));
        $managed = Business::whereIn('_id',$managedIds)->get();
        if ((string)($request->user()->role ?? '') === 'admin') $managed = Business::all();
        $businessPage = $query->latest()->paginate(12);
        foreach ($businessPage as $business) {
            $business->storefront = \App\Models\Storefront::where('business_id',(string)$business->getKey())->first();
        }
        foreach ($managed as $business) {
            $business->storefront = \App\Models\Storefront::where('business_id',(string)$business->getKey())->first();
        }
        return Inertia::render('Marketplace/Businesses', [
            'businesses' => $businessPage,
            'managedBusinesses' => $managed,
            'isAdmin' => (string)($request->user()->role ?? '') === 'admin',
        ]);
    }

    public function products(Request $request)
    {
        $userId = (string) $request->user()->getKey();
        $publishedBusinessIds = \App\Models\Storefront::where('published',true)->pluck('business_id')->map(fn($id)=>(string)$id)->all();
        $publicBusinessIds = Business::where('status','approved')->where('visibility','public')->whereIn('_id',$publishedBusinessIds)->pluck('_id')->map(fn($id)=>(string)$id)->all();
        $query = Product::query();
        $owned = Business::where('owner_id', $userId)->pluck('_id')->map(fn($id)=>(string)$id)->all();
        $member = \App\Models\BusinessMember::where('user_id', $userId)->where('status','active')->whereIn('role',['owner','manager','inventory'])->pluck('business_id')->all();
        $managedIds = array_values(array_unique(array_merge($owned,$member)));
        if ((string)($request->user()->role ?? '') !== 'admin') {
            $query->where(function($q) use ($managedIds, $publicBusinessIds) { $q->whereIn('business_id',$managedIds)->orWhere(function($public) use ($publicBusinessIds) { $public->whereIn('business_id',$publicBusinessIds)->where('active',true)->where('available',true); }); });
        }
        return Inertia::render('Marketplace/Products', [
            'products' => $query->latest()->paginate(24),
            'managedBusinesses' => (string)($request->user()->role ?? '') === 'admin' ? Business::all() : Business::whereIn('_id',$managedIds)->get(),
        ]);
    }

    public function orders(Request $request)
    {
        $orders = Order::where('buyer_id', (string) $request->user()->getKey())
            ->latest()->paginate(10);

        return Inertia::render('Marketplace/Orders', ['orders' => $orders, 'isAdmin' => (string)($request->user()->role ?? '') === 'admin']);
    }

    public function catalog(Request $request)
    {
        $publishedBusinessIds = \App\Models\Storefront::where('published',true)->pluck('business_id')->map(fn($id)=>(string)$id)->all();
        $publicBusinessIds = Business::where('status','approved')->where('visibility','public')->whereIn('_id',$publishedBusinessIds)->pluck('_id')->map(fn($id)=>(string)$id)->all();
        $query = Product::where('active', true)->where('available', true)->whereIn('business_id', $publicBusinessIds);

        if ($request->filled('q')) {
            $q = trim($request->string('q'));
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%")
                    ->orWhere('category', 'like', "%{$q}%");
            });
        }

        return response()->json([
            'data' => $query->limit(50)->get(),
            'meta' => ['domain' => 'team3.marketplace', 'version' => 'v1'],
        ]);
    }

    public function businessesApi()
    {
        $publishedBusinessIds = \App\Models\Storefront::where('published',true)->pluck('business_id')->map(fn($id)=>(string)$id)->all();
        return response()->json([
            'data' => Business::where('status', 'approved')->where('visibility', 'public')->whereIn('_id',$publishedBusinessIds)->get(),
            'meta' => ['domain' => 'team3.marketplace', 'version' => 'v1'],
        ]);
    }

    public function cart(Request $request)
    {
        $payload = $request->validate([
            'product_id' => ['required','string'],
            'quantity' => ['required','integer','min:1','max:20'],
        ]);
        $product = Product::findOrFail($payload['product_id']);
        abort_unless($product->active && $product->available, 422, 'Producto no disponible.');
        $business = Business::find((string) $product->business_id);
        abort_unless($business && $business->status === 'approved' && $business->visibility === 'public', 422, 'El negocio no está disponible.');
        $cart = Cart::firstOrNew(['user_id' => (string) $request->user()->getKey()]);
        $items = $cart->items ?? [];
        $found = false;
        foreach ($items as &$item) {
            if ((string) $item['product_id'] === (string) $product->getKey()) {
                abort_if(((int) $item['quantity'] + $payload['quantity']) > 20, 422, 'Máximo 20 unidades por producto.');
                $item['quantity'] = (int) $item['quantity'] + $payload['quantity'];
                $item['unit_price'] = $this->moneyDecimal($product->price);
                $found = true; break;
            }
        }
        unset($item);
        if (!$found) {
            $items[] = [
                'product_id' => (string) $product->getKey(), 'business_id' => (string) $product->business_id,
                'name' => $product->name, 'unit_price' => $this->moneyDecimal($product->price),
                'quantity' => $payload['quantity'], 'image' => $product->image,
                'inventory_required' => (bool) $product->inventory_required,
            ];
        }
        $cart->items = $items;
        $cart->subtotal = $this->cartSubtotal($items);
        $cart->save();
        return back()->with('success', 'Producto agregado al carrito.');
    }

    public function updateCart(Request $request)
    {
        $data = $request->validate(['items' => ['required','array','min:1','max:50'], 'items.*.product_id' => ['required','string'], 'items.*.quantity' => ['required','integer','min:1','max:20']]);
        $cart = Cart::where('user_id', (string) $request->user()->getKey())->firstOrFail();
        $items = [];
        foreach ($data['items'] as $line) {
            $product = Product::findOrFail($line['product_id']);
            abort_unless($product->active && $product->available, 422, "El producto {$product->name} ya no está disponible.");
            $items[] = ['product_id'=>(string)$product->getKey(),'business_id'=>(string)$product->business_id,'name'=>$product->name,'unit_price'=>$this->moneyDecimal($product->price),'quantity'=>(int)$line['quantity'],'image'=>$product->image,'inventory_required'=>(bool)$product->inventory_required];
        }
        $cart->items = $items; $cart->subtotal = $this->cartSubtotal($items); $cart->save();
        return back()->with('success', 'Carrito actualizado con precios vigentes.');
    }

    public function removeCartItem(Request $request, string $productId)
    {
        $cart = Cart::where('user_id', (string) $request->user()->getKey())->firstOrFail();
        $items = array_values(array_filter($cart->items ?? [], fn($item) => (string)($item['product_id'] ?? '') !== $productId));
        if (!$items) { $cart->delete(); } else { $cart->items = $items; $cart->subtotal = $this->cartSubtotal($items); $cart->save(); }
        return back()->with('success', 'Artículo retirado del carrito.');
    }

    public function clearCart(Request $request)
    {
        Cart::where('user_id', (string) $request->user()->getKey())->delete();
        return back()->with('success', 'Carrito vaciado.');
    }

    private function cartSubtotal(array $items): string
    {
        $cents = 0;
        foreach ($items as $item) {
            $unitCents = $this->moneyCents($item['unit_price']);
            $cents += $unitCents * (int) $item['quantity'];
        }
        return number_format($cents / 100, 2, '.', '');
    }

    private function moneyCents($value): int
    {
        $raw = trim((string) $value);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $raw)) return 0;
        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '0');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function moneyDecimal($value): string
    {
        $cents = $this->moneyCents($value);
        return intdiv($cents, 100).'.'.str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public function checkout(
        Request $request,
        Team2PaymentGateway $payments,
        Team4InventoryGateway $inventory,
        Team7RewardsGateway $rewards
    ) {
        $data = $request->validate([
            'payment_method' => ['required','in:wallet,bonus,points,spei,openpay,mixed'],
            'delivery_point' => ['nullable','string','max:180'],
            'notes' => ['nullable','string','max:500'],
            'idempotency_key' => ['nullable','string','max:120'],
        ]);
        $buyerId = (string) $request->user()->getKey();
        $cart = Cart::where('user_id', $buyerId)->firstOrFail();
        $items = $cart->items ?? [];
        abort_if(empty($items), 422, 'El carrito está vacío.');

        // Re-read every product and price immediately before checkout; do not trust stale cart prices.
        $freshItems = [];
        foreach ($items as $item) {
            $product = Product::findOrFail((string) $item['product_id']);
            abort_unless($product->active && $product->available, 422, "El producto {$product->name} ya no está disponible.");
            $business = Business::find((string) $product->business_id);
            abort_unless($business && $business->status === 'approved' && $business->visibility === 'public', 422, 'Un negocio del carrito ya no está disponible.');
            $freshItems[] = array_merge($item, [
                'business_id' => (string) $product->business_id, 'name' => $product->name,
                'unit_price' => $this->moneyDecimal($product->price),
                'inventory_required' => (bool) $product->inventory_required,
                'quantity' => (int) $item['quantity'],
            ]);
        }
        $businessIds = collect($freshItems)->pluck('business_id')->unique();
        abort_if($businessIds->count() !== 1, 422, 'Por ahora cada pedido debe corresponder a un solo negocio.');
        $businessId = (string) $businessIds->first();
        $business = Business::findOrFail($businessId);
        abort_unless(in_array($data['payment_method'], $business->payment_methods ?? ['wallet'], true), 422, 'Este negocio no acepta el método de pago seleccionado.');

        $subtotal = $this->cartSubtotal($freshItems);
        $idempotency = $data['idempotency_key'] ?? hash('sha256', $buyerId.'|'.(string)$cart->getKey().'|'.(string)($cart->updated_at ?? '').'|'.json_encode($freshItems));
        $existing = Order::where('buyer_id', $buyerId)->where('idempotency_key', $idempotency)->first();
        if ($existing) {
            return redirect()->route('marketplace.orders')->with('success', "El pedido {$existing->folio} ya existe; se evitó duplicar el checkout.");
        }

        $order = Order::create([
            'folio' => 'CD3-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
            'buyer_id' => $buyerId, 'business_id' => $businessId, 'items' => $freshItems,
            'subtotal' => $subtotal, 'discount' => '0.00', 'total' => $subtotal, 'currency' => 'MXN',
            'payment_method' => $data['payment_method'], 'payment_status' => 'pending',
            'status' => 'pending_payment', 'delivery_point' => $data['delivery_point'] ?? null,
            'notes' => $data['notes'] ?? null, 'idempotency_key' => $idempotency,
            'history' => [['status'=>'pending_payment','actor_id'=>$buyerId,'at'=>now()->toISOString(),'reason'=>'checkout_created']],
        ]);
        $intent = PaymentIntent::create([
            'order_id' => (string) $order->getKey(), 'business_id' => $businessId, 'buyer_id' => $buyerId,
            'method' => $data['payment_method'], 'amount' => $subtotal, 'currency' => 'MXN',
            'status' => 'pending', 'provider' => in_array($data['payment_method'], ['wallet','bonus','points','mixed']) ? 'team2/team7' : $data['payment_method'],
            'idempotency_key' => $idempotency, 'metadata' => [],
        ]);

        $inventoryResult = ['status' => 'not_required'];
        try {
            $inventoryLines = array_values(array_filter($freshItems, fn($line) => ($line['inventory_required'] ?? true) === true));
            if ($inventoryLines) {
                $inventoryResult = $inventory->reserve($inventoryLines, (string) $order->getKey());
                abort_unless(($inventoryResult['status'] ?? null) === 'reserved', 422, 'Inventario no pudo reservarse.');
            }
            $paymentResult = in_array($data['payment_method'], ['wallet','bonus','points','mixed'], true)
                ? $payments->charge(['idempotency_key'=>$idempotency,'amount'=>$subtotal,'method'=>$data['payment_method'],'order_id'=>(string)$order->getKey(),'buyer_id'=>$buyerId,'business_id'=>$businessId])
                : ['status'=>'pending_validation','reference'=>strtoupper($data['payment_method']).'-'.Str::upper(Str::random(10)),'provider'=>$data['payment_method']];
        } catch (\Throwable $exception) {
            try { if (($inventoryResult['status'] ?? null) === 'reserved') $inventory->release($freshItems, (string) $order->getKey()); } catch (\Throwable $ignored) {}
            $order->update(['status'=>'payment_failed','payment_status'=>'failed','history'=>array_merge($order->history ?? [], [['status'=>'payment_failed','actor_id'=>$buyerId,'at'=>now()->toISOString(),'reason'=>'integration_error']])]);
            $intent->update(['status'=>'failed','metadata'=>['error_code'=>'integration_error']]);
            report($exception);
            return back()->withErrors(['checkout' => 'No se pudo completar el checkout. No se confirmó el pago; revisa el pedido e inténtalo nuevamente.'])->withInput();
        }

        $provider = (string) ($paymentResult['provider'] ?? '');
        $mock = str_contains(strtolower($provider), 'mock');
        $paid = ($paymentResult['status'] ?? '') === 'authorized' && !$mock;
        $status = $paid ? 'confirmed' : (($paymentResult['status'] ?? '') === 'pending_validation' || $mock ? 'awaiting_payment_validation' : 'payment_failed');
        $paymentStatus = $paid ? 'paid' : (($status === 'payment_failed') ? 'failed' : 'pending');
        if ($mock || $status === 'payment_failed') {
            try {
                $inventoryLines = array_values(array_filter($freshItems, fn($line) => ($line['inventory_required'] ?? true) === true));
                if ($inventoryLines) $inventory->release($inventoryLines, (string) $order->getKey());
                $inventoryResult['release_after_unconfirmed_payment'] = 'attempted';
            } catch (\Throwable $releaseException) {
                report($releaseException);
                $inventoryResult['release_after_unconfirmed_payment'] = 'pending_retry';
            }
        }
        $intent->update([
            'status' => $mock ? 'demo_authorized' : ($paymentResult['status'] ?? 'pending'),
            'reference' => $paymentResult['reference'] ?? null, 'provider' => $provider ?: $intent->provider,
            'metadata' => ['inventory_reservation' => $inventoryResult, 'is_mock' => $mock],
        ]);
        $order->update([
            'payment_status' => $paymentStatus, 'status' => $status,
            'history' => array_merge($order->history ?? [], [['status'=>$status,'actor_id'=>$buyerId,'at'=>now()->toISOString(),'reason'=>$mock ? 'mock_result_not_a_real_charge' : 'payment_result']]),
        ]);
        if ($paid) {
            try {
                $rewardResult = $rewards->earn(['order_id'=>(string)$order->getKey(),'total'=>$subtotal,'business_id'=>$businessId,'buyer_id'=>$buyerId]);
                $intent->update(['metadata'=>array_merge($intent->metadata ?? [], ['rewards_result'=>$rewardResult])]);
            } catch (\Throwable $exception) {
                report($exception);
                $intent->update(['metadata'=>array_merge($intent->metadata ?? [], ['rewards_status'=>'pending_retry'])]);
            }
        }
        if ($paymentStatus !== 'failed') $cart->delete();
        return redirect()->route('marketplace.orders')->with('success', $mock
            ? "Pedido {$order->folio} creado en modo demostración. El adaptador simulado no representa un cobro real."
            : "Pedido {$order->folio} creado; estado: {$order->status}.");
    }

    public function returns(Request $request)
    {
        $userId = (string) $request->user()->getKey();
        $query = ReturnRequest::query()->latest();
        if ((string) ($request->user()->role ?? '') !== 'admin') {
            $owned = Business::where('owner_id', $userId)->pluck('_id')->map(fn($id)=>(string)$id)->all();
            $member = \App\Models\BusinessMember::where('user_id',$userId)->where('status','active')->whereIn('role',['owner','manager'])->pluck('business_id')->all();
            $query->where(function($q) use ($userId,$owned,$member) {
                $q->where('buyer_id',$userId)->orWhereIn('business_id',array_values(array_unique(array_merge($owned,$member))));
            });
        }
        return Inertia::render('Marketplace/Returns', ['returns' => $query->paginate(20), 'isAdmin' => (string)($request->user()->role ?? '') === 'admin']);
    }

}
