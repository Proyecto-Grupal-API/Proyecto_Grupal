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
        $businesses = Business::where('status', 'approved')
            ->where('visibility', 'public')
            ->limit(12)->get();

        $products = Product::where('active', true)
            ->where('available', true)
            ->limit(24)->get();

        $stats = [
            'businesses' => Business::where('status', 'approved')->count(),
            'products' => Product::where('active', true)->count(),
            'orders' => Order::count(),
            'sales' => (float) Order::where('payment_status', 'paid')->sum('total'),
        ];

        return Inertia::render('Marketplace/Index', [
            'businesses' => $businesses,
            'products' => $products,
            'stats' => $stats,
            'cart' => Cart::where('user_id', (string) $request->user()->getKey())->first()?->toArray(),
        ]);
    }

    public function businesses()
    {
        return Inertia::render('Marketplace/Businesses', [
            'businesses' => Business::latest()->paginate(12),
        ]);
    }

    public function products()
    {
        return Inertia::render('Marketplace/Products', [
            'products' => Product::latest()->paginate(24),
        ]);
    }

    public function orders(Request $request)
    {
        $orders = Order::where('buyer_id', (string) $request->user()->getKey())
            ->latest()->paginate(10);

        return Inertia::render('Marketplace/Orders', ['orders' => $orders]);
    }

    public function catalog(Request $request)
    {
        $query = Product::where('active', true)->where('available', true);

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
        return response()->json([
            'data' => Business::where('status', 'approved')->where('visibility', 'public')->get(),
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

        $cart = Cart::firstOrNew(['user_id' => (string) $request->user()->getKey()]);
        $items = $cart->items ?? [];
        $found = false;

        foreach ($items as &$item) {
            if ($item['product_id'] === (string) $product->getKey()) {
                $item['quantity'] += $payload['quantity'];
                $found = true;
                break;
            }
        }
        unset($item);

        if (!$found) {
            $items[] = [
                'product_id' => (string) $product->getKey(),
                'business_id' => (string) $product->business_id,
                'name' => $product->name,
                'unit_price' => (float) $product->price,
                'quantity' => $payload['quantity'],
                'image' => $product->image,
            ];
        }

        $cart->items = $items;
        $cart->subtotal = collect($items)->sum(fn ($i) => $i['unit_price'] * $i['quantity']);
        $cart->save();

        return back()->with('success', 'Producto agregado al carrito.');
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
        ]);

        $cart = Cart::where('user_id', (string) $request->user()->getKey())->firstOrFail();
        $items = $cart->items ?? [];
        abort_if(empty($items), 422, 'El carrito está vacío.');

        $businessIds = collect($items)->pluck('business_id')->unique();
        abort_if($businessIds->count() !== 1, 422, 'El checkout demo requiere productos de un solo negocio.');

        $subtotal = (float) $cart->subtotal;
        $orderId = (string) Str::uuid();
        $idempotency = hash('sha256', $orderId.$request->user()->getKey());

        $inventoryResult = $inventory->reserve($items, $orderId);

        $order = Order::create([
            'folio' => 'CD3-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
            'buyer_id' => (string) $request->user()->getKey(),
            'business_id' => (string) $businessIds->first(),
            'items' => $items,
            'subtotal' => $subtotal,
            'discount' => 0,
            'total' => $subtotal,
            'currency' => 'MXN',
            'payment_method' => $data['payment_method'],
            'payment_status' => 'pending',
            'status' => 'pending_payment',
            'delivery_point' => $data['delivery_point'] ?? null,
            'notes' => $data['notes'] ?? null,
            'idempotency_key' => $idempotency,
        ]);

        $intent = PaymentIntent::create([
            'order_id' => (string) $order->getKey(),
            'business_id' => (string) $businessIds->first(),
            'buyer_id' => (string) $request->user()->getKey(),
            'method' => $data['payment_method'],
            'amount' => $subtotal,
            'currency' => 'MXN',
            'status' => 'pending',
            'provider' => in_array($data['payment_method'], ['wallet','bonus','points']) ? 'team2/team7' : $data['payment_method'],
            'idempotency_key' => $idempotency,
            'metadata' => ['inventory_reservation' => $inventoryResult],
        ]);

        if (in_array($data['payment_method'], ['wallet','bonus','points','mixed'])) {
            $paymentResult = $payments->charge([
                'idempotency_key' => $idempotency,
                'amount' => $subtotal,
                'method' => $data['payment_method'],
                'order_id' => (string) $order->getKey(),
            ]);
        } else {
            $paymentResult = [
                'status' => 'pending_validation',
                'reference' => 'DIRECT-'.strtoupper(Str::random(10)),
                'provider' => $data['payment_method'],
            ];
        }

        $intent->update([
            'status' => $paymentResult['status'],
            'reference' => $paymentResult['reference'],
        ]);

        $paid = $paymentResult['status'] === 'authorized';

        $order->update([
            'payment_status' => $paid ? 'paid' : 'pending',
            'status' => $paid ? 'confirmed' : 'awaiting_payment_validation',
        ]);

        if ($paid) {
            $rewards->earn([
                'order_id' => (string) $order->getKey(),
                'total' => $subtotal,
                'business_id' => (string) $businessIds->first(),
                'buyer_id' => (string) $request->user()->getKey(),
            ]);
        }

        $cart->delete();

        return redirect()->route('marketplace.orders')
            ->with('success', "Pedido {$order->folio} creado correctamente.");
    }
}
