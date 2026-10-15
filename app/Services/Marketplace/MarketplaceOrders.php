<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\AuditEvent;
use App\Models\Marketplace\Business;
use App\Models\Marketplace\Cart;
use App\Models\Marketplace\Order;
use App\Models\Marketplace\PaymentIntent;
use App\Models\Marketplace\Product;
use App\Models\Marketplace\Storefront;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MarketplaceOrders
{
    public function __construct(private MarketplaceIntegration $integration) {}

    public static function cents($value): int
    {
        $raw = trim((string) $value);
        abort_unless(preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $raw), 422, 'Precio inválido.');
        [$whole,$fraction] = array_pad(explode('.', $raw, 2), 2, '0');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function locked(string $key, Closure $callback)
    {
        $lock = Cache::lock('marketplace:'.$key, 60);
        abort_unless($lock->get(), 409, 'Esta operación está en curso. Espera unos segundos y vuelve a intentarlo.');
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    public static function publicBusiness(string $id): Business
    {
        $business = Business::findOrFail($id);
        abort_unless($business->status === 'approved' && $business->visibility === 'public'
            && Storefront::where('business_id', $id)->where('published', true)->exists(), 422, 'La tienda ya no está disponible.');

        return $business;
    }

    public function checkout(string $buyer, array $data): Order
    {
        return self::locked('cart:'.$buyer, function () use ($buyer, $data) {
            $hash = hash('sha256', json_encode([$data['cart_id'], (int) $data['cart_version'], $data['payment_method'], $data['delivery_point'] ?? '', $data['notes'] ?? ''], JSON_THROW_ON_ERROR));
            $existing = Order::where('buyer_id', $buyer)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->request_hash === $hash, 409, 'La misma clave de pedido se usó con datos diferentes.');

                return $existing;
            }
            $cart = Cart::where('user_id', $buyer)->firstOrFail();
            abort_unless((string) $cart->getKey() === $data['cart_id'] && (int) $cart->version === (int) $data['cart_version'], 409, 'El carrito cambió. Recarga antes de confirmar.');
            abort_unless(! empty($cart->items), 422, 'El carrito está vacío.');
            $items = [];
            $total = 0;
            $inventory = [];
            foreach ($cart->items as $line) {
                $product = Product::findOrFail($line['product_id']);
                abort_unless($product->active && $product->available, 422, 'Un artículo ya no está disponible.');
                self::publicBusiness((string) $product->business_id);
                $price = self::cents($product->price);
                abort_unless($price === self::cents($line['unit_price']), 422, 'Un precio cambió. Actualiza el carrito antes de confirmar.');
                $quantity = (int) $line['quantity'];
                abort_unless($quantity >= 1 && $quantity <= 20, 422, 'Cantidad fuera del límite permitido.');
                $item = array_merge($line, ['business_id' => (string) $product->business_id, 'name' => $product->name, 'unit_price' => self::decimal($price), 'quantity' => $quantity, 'inventory_required' => (bool) $product->inventory_required]);
                $items[] = $item;
                $total += $price * $quantity;
                if ($product->inventory_required) {
                    $this->integration->validateInventory((string) $product->business_id, $product->toArray());
                    $inventory[] = ['product_id' => $product->inventory_product_id, 'location_id' => $product->inventory_location_id, 'variant_id' => null, 'quantity' => $quantity];
                }
            }
            $ids = collect($items)->pluck('business_id')->unique();
            abort_unless($ids->count() === 1, 422, 'Cada pedido corresponde a un solo negocio.');
            $businessId = $ids->first();
            $business = self::publicBusiness($businessId);
            abort_unless(in_array($data['payment_method'], $business->payment_methods ?? [], true), 422, 'El negocio no acepta ese método de pago.');
            $order = DB::connection('marketplace')->transaction(function () use ($buyer, $businessId, $items, $total, $data, $hash, $inventory) {
                $order = Order::create(['folio' => 'CD3-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
                    'buyer_id' => $buyer, 'business_id' => $businessId, 'items' => $items, 'subtotal' => self::decimal($total), 'discount' => '0.00', 'total' => self::decimal($total), 'total_cents' => $total,
                    'currency' => 'MXN', 'payment_method' => $data['payment_method'], 'payment_status' => 'pending', 'status' => 'pending_inventory',
                    'delivery_point' => $data['delivery_point'] ?? null, 'notes' => $data['notes'] ?? null, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
                    'cart_id' => $data['cart_id'], 'cart_version' => $data['cart_version'], 'inventory_state' => 'new', 'hold_expires_at' => now()->addMinutes(config('marketplace.hold_minutes', 15)),
                    'history' => [['status' => 'pending_inventory', 'actor_id' => $buyer, 'at' => now()->toISOString()]]]);
                $order->update(['reserve_payload' => ['order_id' => (string) $order->getKey(), 'items' => $inventory, 'idempotency_key' => 'marketplace-reserve:'.$order->getKey(),
                    'source' => 'CHECKOUT', 'expires_at' => $order->hold_expires_at->toISOString(), 'actor_id' => $buyer]]);
                PaymentIntent::create(['order_id' => (string) $order->getKey(), 'business_id' => $businessId, 'buyer_id' => $buyer, 'method' => $data['payment_method'],
                    'amount' => self::decimal($total), 'currency' => 'MXN', 'status' => 'pending_integration', 'provider' => 'pending_finance', 'idempotency_key' => $data['idempotency_key'], 'metadata' => []]);
                $cart = Cart::where('user_id', $buyer)->where('_id', $data['cart_id'])->where('version', $data['cart_version'])->firstOrFail();
                $cart->delete();

                return $order;
            });
            $this->reconcile($order);
            AuditEvent::create(['actor_id' => $buyer, 'business_id' => $businessId, 'entity_type' => 'order', 'entity_id' => (string) $order->getKey(),
                'action' => 'checkout_created', 'to_status' => $order->fresh()->status, 'details' => ['total_cents' => $total], 'occurred_at' => now()]);

            return $order->fresh();
        });
    }

    public function cancel(Order $order, string $actor, string $reason = ''): void
    {
        self::locked('order:'.$order->getKey(), function () use ($order, $actor, $reason) {
            $order->refresh();
            if ($order->status === 'cancelled') {
                return;
            }
            abort_unless(in_array($order->status, ['pending_inventory', 'awaiting_payment', 'inventory_review'], true) && $order->payment_status === 'pending', 422, 'Este pedido no admite cancelación antes del pago.');
            $order->update(['status' => 'cancelled', 'cancellation_reason' => $reason, 'history' => array_merge($order->history ?? [], [['status' => 'cancelled', 'actor_id' => $actor, 'at' => now()->toISOString(), 'reason' => $reason]])]);
            PaymentIntent::where('order_id', (string) $order->getKey())->update(['status' => 'cancelled']);
        });
        $this->reconcile($order);
    }

    public function reconcile(Order $order): void
    {
        self::locked('order:'.$order->getKey(), function () use ($order) {
            $order->refresh();
            $order->touch(); // Rotate the bounded scheduler batch so unknown results cannot starve later orders.
            if ($order->payment_status !== 'pending') {
                return;
            }
            if (! in_array($order->status, ['cancelled', 'expired'], true) && $order->hold_expires_at?->isPast()) {
                $order->update(['status' => 'expired', 'history' => array_merge($order->history ?? [], [['status' => 'expired', 'actor_id' => 'SYSTEM', 'at' => now()->toISOString()]])]);
                PaymentIntent::where('order_id', (string) $order->getKey())->update(['status' => 'expired']);
            }
            $terminal = in_array($order->status, ['cancelled', 'expired'], true);
            if (empty($order->reserve_payload['items'])) {
                $order->update(['inventory_state' => 'not_required', 'status' => $terminal ? $order->status : 'awaiting_payment']);

                return;
            }
            try {
                if (in_array($order->inventory_state, ['unknown', 'release_pending'], true)) {
                    $result = $this->integration->inventory($order->business_id, 'POST', 'integration/reservation-status', [
                        'order_id' => (string) $order->getKey(), 'idempotency_key' => $order->reserve_payload['idempotency_key']]);
                    if (($result['state'] ?? '') === 'completed') {
                        $this->adopt($order, $result);
                    } elseif (($result['state'] ?? '') === 'rejected') {
                        $order->update(['inventory_state' => 'rejected', 'status' => $terminal ? $order->status : 'stock_unavailable']);

                        return;
                    } elseif (($result['state'] ?? '') !== 'not_found' || $terminal) {
                        return;
                    } else {
                        $order->update(['inventory_state' => 'new']);
                    }
                }
                if ($order->inventory_state === 'new') {
                    if ($terminal) {
                        $order->update(['inventory_state' => 'not_attempted']);

                        return;
                    }
                    // Persist unknown before HTTP: a crash or timeout must never authorize a second intent.
                    $order->update(['inventory_state' => 'unknown']);
                    $result = $this->integration->inventory($order->business_id, 'POST', 'integration/reservations', $order->reserve_payload);
                    $this->adopt($order, $result);
                }
                if ($terminal && $order->inventory_state === 'reserved' && $order->reservation_id) {
                    $order->update(['inventory_state' => 'release_pending']);
                    $result = $this->integration->inventory($order->business_id, 'POST', 'integration/reservations/'.rawurlencode($order->reservation_id).'/release', [
                        'order_id' => (string) $order->getKey(), 'reason' => $order->status, 'idempotency_key' => 'marketplace-release:'.$order->getKey()]);
                    abort_unless(($result['reservation_id'] ?? null) === $order->reservation_id && ($result['status'] ?? '') === 'RELEASED', 503, 'Inventarios no confirmó la liberación.');
                    $order->update(['inventory_state' => 'released']);
                }
            } catch (HttpException $e) {
                if ($e->getStatusCode() === 409 && $order->inventory_state === 'unknown') {
                    $order->update(['inventory_state' => 'rejected', 'status' => $terminal ? $order->status : 'stock_unavailable']);
                } else {
                    Log::warning('marketplace.inventory.pending', ['order_id' => (string) $order->getKey(), 'business_id' => $order->business_id, 'state' => $order->inventory_state]);
                }
            }
        });
    }

    private function adopt(Order $order, array $result): void
    {
        $state = $result['status'] ?? null;
        $id = $result['reservation_id'] ?? null;
        abort_unless(is_string($id) && $id !== '' && in_array($state, ['RESERVED', 'RELEASED', 'CONFIRMED'], true), 503, 'Respuesta de reserva inválida.');
        $terminal = in_array($order->status,['cancelled', 'expired'],true);
        $order->update(['reservation_id' => $id, 'inventory_state' => strtolower($state),
            'status' => $terminal ? $order->status : ($state === 'RESERVED' ? 'awaiting_payment' : ($state === 'RELEASED' ? 'expired' : 'inventory_review'))]);
    }
}
