<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Business;
use App\Models\Marketplace\Cart;
use App\Models\Marketplace\Order;
use App\Models\Marketplace\Product;
use App\Models\Marketplace\ReturnRequest;
use App\Models\Marketplace\Storefront;
use App\Services\Marketplace\MarketplaceIntegration;
use App\Services\Marketplace\MarketplaceOrders;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MarketplaceController extends Controller
{
    public function __construct(private MarketplaceIntegration $integration) {}

    private function publicIds(): array
    {
        $published = Storefront::where('published', true)->pluck('business_id')->all();

        return Business::where('status', 'approved')->where('visibility', 'public')->whereIn('_id', $published)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    private function displayProducts($products): void
    {
        foreach ($products as $product) {
            $product->stock_snapshot = null;
            if (! $product->inventory_required) {
                continue;
            }
            try {
                $catalog = $this->integration->inventoryCatalog((string) $product->business_id);
                $product->stock_snapshot = collect($catalog['inventory'])->filter(fn ($row) => (string) ($row['product_id'] ?? '') === $product->inventory_product_id
                    && (string) ($row['location_id'] ?? '') === $product->inventory_location_id && empty($row['variant_id']))->sum('available');
            } catch (HttpException $e) {
                $product->inventory_unavailable = true;
            }
        }
    }

    public function index(Request $request)
    {
        $ids = $this->publicIds();
        $products = Product::where('active', true)->where('available', true)->whereIn('business_id', $ids)->limit(24)->get();
        $this->displayProducts($products);
        $cart = Cart::where('user_id', (string) $request->user()->getKey())->first();

        return Inertia::render('Marketplace/Index', [
            'businesses' => Business::whereIn('_id', $ids)->limit(12)->get(), 'products' => $products,
            'stats' => ['businesses' => count($ids), 'products' => Product::whereIn('business_id', $ids)->where('active', true)->count(),
                'orders' => Order::where('buyer_id', (string) $request->user()->getKey())->count(), 'sales' => 0],
            'cart' => $cart?->toArray(), 'cartBusiness' => $cart && ! empty($cart->items) ? Business::find($cart->items[0]['business_id']) : null,
            'isAdmin' => (bool) $request->attributes->get('marketplace_reviewer')]);
    }

    public function businesses(Request $request)
    {
        $user = (string) $request->user()->getKey();
        $managedIds = $this->integration->businesses($user, 'business.operate');
        $ownerIds = $this->integration->businesses($user, 'business.manage');
        $query = Business::query();
        $reviewer = (bool) $request->attributes->get('marketplace_reviewer');
        if (! $reviewer) {
            $query->whereIn('_id', array_values(array_unique(array_merge($this->publicIds(), $managedIds))));
        }
        $businesses = $query->latest()->paginate(12);
        $managed = Business::whereIn('_id', $managedIds)->get();
        foreach ($businesses as $b) {
            $b->storefront = Storefront::where('business_id', (string) $b->getKey())->first();
            $b->can_configure = in_array((string) $b->getKey(), $ownerIds, true);
        }

        return Inertia::render('Marketplace/Businesses', ['businesses' => $businesses, 'managedBusinesses' => $managed, 'isAdmin' => $reviewer]);
    }

    public function products(Request $request)
    {
        $managedIds = $this->integration->businesses((string) $request->user()->getKey(), 'business.inventory.operate');
        $public = $this->publicIds();
        $products = Product::where(function ($q) use ($managedIds, $public) {
            $q->whereIn('business_id', $managedIds)
                ->orWhere(function ($q) use ($public) {
                    $q->whereIn('business_id', $public)->where('active', true)->where('available', true);
                });
        })->latest()->paginate(24);
        $this->displayProducts($products);
        $catalogs = [];
        foreach ($managedIds as $id) {
            try {
                $catalogs[$id] = $this->integration->inventoryCatalog($id);
            } catch (HttpException $e) {
                $catalogs[$id] = ['products' => [], 'locations' => [], 'inventory' => [], 'unavailable' => true];
            }
        }

        return Inertia::render('Marketplace/Products', ['products' => $products, 'managedBusinesses' => Business::whereIn('_id', $managedIds)->get(), 'inventoryCatalogs' => $catalogs]);
    }

    public function orders(Request $request)
    {
        $orders = Order::where('buyer_id', (string) $request->user()->getKey())
            ->latest()->paginate(10);

        return Inertia::render('Marketplace/Orders', ['orders' => $orders, 'isAdmin' => false]);
    }

    public function catalog(Request $request)
    {
        $query = Product::where('active', true)->where('available', true)->whereIn('business_id', $this->publicIds());
        if ($request->filled('q')) {
            $q = substr(trim((string) $request->input('q')), 0, 120);
            $query->where('name', 'like', '%'.$q.'%');
        }
        if ($request->filled('business_id')) {
            $query->where('business_id', (string) $request->input('business_id'));
        }
        $page = $query->paginate(24);
        $this->displayProducts($page);
        $page->getCollection()->transform(fn ($p) => ['id' => (string) $p->getKey(), 'business_id' => $p->business_id, 'name' => $p->name, 'description' => $p->description,
            'kind' => $p->kind, 'category' => $p->category, 'image' => $p->image, 'price' => $p->price, 'currency' => $p->currency, 'available_stock' => $p->stock_snapshot]);

        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }

    public function businessesApi()
    {
        return response()->json(Business::whereIn('_id', $this->publicIds())->paginate(24)->through(fn ($b) => [
            'id' => (string) $b->getKey(), 'name' => $b->name, 'description' => $b->description, 'type' => $b->type, 'organization_id' => $b->organization_id]))
            ->header('Cache-Control', 'private, no-store');
    }

    public function cart(Request $request)
    {
        return MarketplaceOrders::locked('cart:'.(string) $request->user()->getKey(), function () use ($request) {
            $payload = $request->validate([
                'product_id' => ['required', 'string'],
                'quantity' => ['required', 'integer', 'min:1', 'max:20'],
            ]);
            $product = Product::findOrFail($payload['product_id']);
            abort_unless($product->active && $product->available, 422, 'Producto no disponible.');
            $business = MarketplaceOrders::publicBusiness((string) $product->business_id);
            $cart = Cart::firstOrNew(['user_id' => (string) $request->user()->getKey()]);
            $items = $cart->items ?? [];
            abort_if($items && $items[0]['business_id'] !== (string) $product->business_id, 422, 'Vacía el carrito para comprar en otro negocio.');
            $found = false;
            foreach ($items as &$item) {
                if ((string) $item['product_id'] === (string) $product->getKey()) {
                    abort_if(((int) $item['quantity'] + $payload['quantity']) > 20, 422, 'Máximo 20 unidades por producto.');
                    $item['quantity'] = (int) $item['quantity'] + $payload['quantity'];
                    $item['unit_price'] = $this->moneyDecimal($product->price);
                    $found = true;
                    break;
                }
            }
            unset($item);
            if (! $found) {
                abort_if(count($items) >= 50, 422, 'Máximo 50 artículos diferentes por carrito.');
                $items[] = [
                    'product_id' => (string) $product->getKey(), 'business_id' => (string) $product->business_id,
                    'name' => $product->name, 'unit_price' => $this->moneyDecimal($product->price),
                    'quantity' => $payload['quantity'], 'image' => $product->image,
                    'inventory_required' => (bool) $product->inventory_required,
                ];
            }
            $cart->items = $items;
            $cart->subtotal = $this->cartSubtotal($items);
            $cart->version = (int) ($cart->version ?? 0) + 1;
            $cart->save();

            return back()->with('success', 'Producto agregado al carrito.');
        });
    }

    public function updateCart(Request $request)
    {
        return MarketplaceOrders::locked('cart:'.(string) $request->user()->getKey(), function () use ($request) {
            $data = $request->validate(['items' => ['required', 'array', 'min:1', 'max:50'], 'items.*.product_id' => ['required', 'string', 'distinct'], 'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20']]);
            $cart = Cart::where('user_id', (string) $request->user()->getKey())->firstOrFail();
            $items = [];
            foreach ($data['items'] as $line) {
                $product = Product::findOrFail($line['product_id']);
                MarketplaceOrders::publicBusiness((string) $product->business_id);
                abort_unless($product->active && $product->available, 422, "El producto {$product->name} ya no está disponible.");
                $items[] = ['product_id' => (string) $product->getKey(), 'business_id' => (string) $product->business_id, 'name' => $product->name, 'unit_price' => $this->moneyDecimal($product->price), 'quantity' => (int) $line['quantity'], 'image' => $product->image, 'inventory_required' => (bool) $product->inventory_required];
            }
            abort_if(collect($items)->pluck('business_id')->unique()->count() > 1, 422, 'Cada carrito corresponde a un solo negocio.');
            $cart->items = $items;
            $cart->subtotal = $this->cartSubtotal($items);
            $cart->version = (int) ($cart->version ?? 0) + 1;
            $cart->save();

            return back()->with('success', 'Carrito actualizado con precios vigentes.');
        });
    }

    public function removeCartItem(Request $request, string $productId)
    {
        return MarketplaceOrders::locked('cart:'.(string) $request->user()->getKey(), function () use ($request, $productId) {
            $cart = Cart::where('user_id', (string) $request->user()->getKey())->firstOrFail();
            $items = array_values(array_filter($cart->items ?? [], fn ($item) => (string) ($item['product_id'] ?? '') !== $productId));
            if (! $items) {
                $cart->delete();
            } else {
                abort_if(collect($items)->pluck('business_id')->unique()->count() > 1, 422, 'Cada carrito corresponde a un solo negocio.');
                $cart->items = $items;
                $cart->subtotal = $this->cartSubtotal($items);
                $cart->version = (int) ($cart->version ?? 0) + 1;
                $cart->save();
            }

            return back()->with('success', 'Artículo retirado del carrito.');
        });
    }

    public function clearCart(Request $request)
    {
        return MarketplaceOrders::locked('cart:'.(string) $request->user()->getKey(), function () use ($request) {
            Cart::where('user_id', (string) $request->user()->getKey())->delete();

            return back()->with('success', 'Carrito vaciado.');
        });
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
        return MarketplaceOrders::cents($value);
    }

    private function moneyDecimal($value): string
    {
        $cents = $this->moneyCents($value);

        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public function checkout(Request $request, MarketplaceOrders $orders)
    {
        $data = $request->validate(['payment_method' => ['required', 'in:wallet,bonus,points,spei,openpay,mixed'], 'delivery_point' => ['nullable', 'string', 'max:180'],
            'notes' => ['nullable', 'string', 'max:500'], 'idempotency_key' => ['required', 'string', 'min:8', 'max:120'], 'cart_id' => ['required', 'string', 'max:100'], 'cart_version' => ['required', 'integer', 'min:1']]);
        $order = $orders->checkout((string) $request->user()->getKey(), $data);
        $message = match ($order->status) {
            'stock_unavailable' => "Pedido {$order->folio}: no se pudo reservar por falta de existencias. No se realizó ningún cobro.",
            'pending_inventory','inventory_review' => "Pedido {$order->folio}: la reserva aún no está confirmada. No se realizó ningún cobro.",
            default => "Pedido {$order->folio} registrado. El pago está pendiente; no se ha realizado ningún cobro.",
        };

        return redirect()->route('marketplace.orders')->with('success', $message);
    }

    public function returns(Request $request)
    {
        $user = (string) $request->user()->getKey();
        $ids = $this->integration->businesses($user, 'business.operate');
        $returns = ReturnRequest::where(function ($q) use ($user, $ids) {
            $q->where('buyer_id', $user)->orWhereIn('business_id', $ids);
        })->latest()->paginate(20);

        return Inertia::render('Marketplace/Returns', ['returns' => $returns, 'isAdmin' => false, 'canResolveIds' => $returns->filter(fn ($r) => in_array($r->business_id,$ids,true))->map(fn ($r) => (string) $r->getKey())->values()]);
    }
}
