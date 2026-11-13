<?php

use App\Http\Middleware\EnsureRequiredTwoFactorAuthentication;
use App\Http\Middleware\EnsureSessionIsActive;
use App\Http\Middleware\TrackDeviceSession;
use App\Models\Marketplace\AuditEvent;
use App\Models\Marketplace\Business;
use App\Models\Marketplace\BusinessApplication;
use App\Models\Marketplace\Cart;
use App\Models\Marketplace\Order;
use App\Models\Marketplace\Product;
use App\Models\Marketplace\ReturnRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

// Diseño E3 3.1–3.5, 3.9 y 3.10: solicitud → aprobación → tienda → catálogo → carrito → pedido → transición/cancelación →
// postventa, con autorización por negocio consultada a E1 (falsa en la prueba) y panel del vendedor coherente con la base.
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->withoutMiddleware([TrackDeviceSession::class, EnsureSessionIsActive::class, EnsureRequiredTwoFactorAuthentication::class]);
    config(['marketplace.identidad' => ['url' => 'http://identity.test', 'client_id' => 'marketplace-test', 'client_secret' => 'secret'],
        'marketplace.comunidad' => ['url' => 'http://community.test', 'client_id' => 'community-test', 'client_secret' => 'secret'],
        'marketplace.inventory.url' => 'http://inventory.test', 'marketplace.inventory.secret' => 'inventory-secret']);
    $make = fn () => User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]);
    $this->admin = $make(); $this->admin->assignRole(Role::ADMIN);
    $this->seller = $make(); $this->buyer = $make(); $this->stranger = $make();
    $this->owners = [];
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        $url = $request->url(); $data = $request->data();
        if (str_contains($url, '/api/oauth/token')) { return Http::response(['access_token' => 'fixture', 'expires_in' => 300]); }
        if (str_contains($url, '/identity/business-owner/provision')) {
            $this->owners[$data['business_id']] = $data['subject_id'];
            return Http::response(['subject_id' => $data['subject_id'], 'business_id' => $data['business_id'], 'result' => 'provisioned']);
        }
        if (str_contains($url, '/identity/assignments')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q); $q += $data;
            $owner = ($this->owners[$q['scope_id']] ?? null) === $q['subject_id'];
            return Http::response(['subject_id' => $q['subject_id'], 'scope_type' => 'business', 'scope_id' => $q['scope_id'],
                'membership_status' => $owner ? 'active' : null, 'roles' => $owner ? [['role_key' => 'business_owner', 'status' => 'active']] : []]);
        }
        if (str_contains($url, '/identity/authorization/check')) {
            return Http::response(['authorized' => ($this->owners[$data['scope_id']] ?? null) === $data['subject_id']]);
        }
        if (str_contains($url, '/api/equipo4/integration/businesses')) { return Http::response(['business_id' => $data['business_id'], 'active' => true]); }
        throw new RuntimeException('Petición externa no esperada: '.$url);
    });
});

function marketplaceStore($test): Business
{
    $test->actingAs($test->seller)->post('/tienda/solicitudes', ['name' => 'X', 'type' => 'service', 'description' => 'Corta'])->assertSessionHasErrors('name');
    $test->post('/tienda/solicitudes', ['name' => 'Impresiones Rápidas', 'type' => 'service', 'description' => 'Impresión y engargolado para estudiantes.'])->assertSessionHasNoErrors();
    $application = BusinessApplication::where('applicant_id', (string) $test->seller->getKey())->firstOrFail();
    expect($application->status)->toBe('pending');
    $test->actingAs($test->stranger)->post('/tienda/solicitudes/'.$application->getKey().'/resolver', ['decision' => 'approved'])->assertForbidden();
    $test->actingAs($test->admin)->post('/tienda/solicitudes/'.$application->getKey().'/resolver', ['decision' => 'approved'])->assertSessionHasNoErrors();
    $business = Business::findOrFail('BUS-E3-'.$application->getKey());
    expect($business->status)->toBe('approved')->and($application->fresh()->status)->toBe('approved');
    $test->actingAs($test->seller)->put('/tienda/negocios/'.$business->getKey().'/tienda', ['name' => 'Impresiones Rápidas', 'published' => true])->assertSessionHasNoErrors();

    return $business;
}

function marketplaceProduct($test, Business $business): Product
{
    $base = ['business_id' => (string) $business->getKey(), 'name' => 'Engargolado', 'kind' => 'service', 'category' => 'Impresión', 'currency' => 'MXN',
        'active' => true, 'available' => true, 'inventory_required' => false];
    $test->actingAs($test->seller)->post('/tienda/productos', $base + ['price' => 0])->assertSessionHasErrors('price');
    $test->actingAs($test->stranger)->post('/tienda/productos', $base + ['price' => 25.5])->assertForbidden();
    $test->actingAs($test->seller)->post('/tienda/productos', $base + ['price' => 25.5])->assertSessionHasNoErrors();

    return Product::where('business_id', (string) $business->getKey())->firstOrFail();
}

function marketplaceCheckout($test, string $key): Order
{
    $cart = Cart::where('user_id', (string) $test->buyer->getKey())->firstOrFail();
    $test->actingAs($test->buyer)->post('/tienda/checkout', ['payment_method' => 'wallet', 'idempotency_key' => $key, 'cart_id' => (string) $cart->getKey(), 'cart_version' => $cart->version])
        ->assertRedirect(route('marketplace.orders'));

    return Order::where('buyer_id', (string) $test->buyer->getKey())->where('idempotency_key', $key)->firstOrFail();
}

test('an approved business publishes a service that a buyer can order and cancel before payment', function () {
    $business = marketplaceStore($this);
    $product = marketplaceProduct($this, $business);
    $this->actingAs($this->buyer)->post('/tienda/carrito', ['product_id' => (string) $product->getKey(), 'quantity' => 21])->assertSessionHasErrors('quantity');
    $this->post('/tienda/carrito', ['product_id' => (string) $product->getKey(), 'quantity' => 1])->assertSessionHasNoErrors();
    $this->put('/tienda/carrito', ['items' => [['product_id' => (string) $product->getKey(), 'quantity' => 2]]])->assertSessionHasNoErrors();
    expect(Cart::where('user_id', (string) $this->buyer->getKey())->value('subtotal'))->toBe('51.00');
    $order = marketplaceCheckout($this, 'pedido-flujo-0001');
    expect($order->status)->toBe('awaiting_payment')->and($order->total_cents)->toBe(5100)->and($order->inventory_state)->toBe('not_required')
        ->and(Cart::where('user_id', (string) $this->buyer->getKey())->exists())->toBeFalse();
    $this->post('/tienda/pedidos/'.$order->getKey().'/estado', ['status' => 'preparing'])->assertForbidden();
    $this->actingAs($this->seller)->post('/tienda/pedidos/'.$order->getKey().'/estado', ['status' => 'preparing'])->assertSessionHasErrors('marketplace');
    $this->actingAs($this->buyer)->post('/tienda/pedidos/'.$order->getKey().'/estado', ['status' => 'cancelled', 'reason' => 'Ya no lo necesito'])->assertSessionHasNoErrors();
    expect($order->fresh()->status)->toBe('cancelled');
    $this->actingAs($this->seller)->delete('/tienda/productos/'.$product->getKey())->assertSessionHasNoErrors();
    expect($product->fresh()->active)->toBeFalse()->and(AuditEvent::where('entity_id', (string) $product->getKey())->where('action', 'product_deactivated')->exists())->toBeTrue();
});

test('a paid order advances through its states, accepts an after-sales request and feeds the seller dashboard', function () {
    $business = marketplaceStore($this);
    $product = marketplaceProduct($this, $business);
    $this->actingAs($this->buyer)->post('/tienda/carrito', ['product_id' => (string) $product->getKey(), 'quantity' => 3])->assertSessionHasNoErrors();
    $order = marketplaceCheckout($this, 'pedido-flujo-0002');
    // La confirmación del cobro pertenece a Finanzas (E2); se simula su resultado ya confirmado.
    $order->update(['payment_status' => 'paid', 'status' => 'confirmed']);
    $this->actingAs($this->seller);
    foreach (['preparing', 'ready', 'delivered'] as $status) {
        $this->post('/tienda/pedidos/'.$order->getKey().'/estado', ['status' => $status])->assertSessionHasNoErrors();
    }
    $this->post('/tienda/pedidos/'.$order->getKey().'/estado', ['status' => 'preparing'])->assertSessionHasErrors('marketplace');
    $this->actingAs($this->stranger)->post('/tienda/pedidos/'.$order->getKey().'/postventa', ['reason' => 'No es mío'])->assertForbidden();
    $this->actingAs($this->buyer)->post('/tienda/pedidos/'.$order->getKey().'/postventa', ['reason' => 'Mal'])->assertSessionHasErrors('reason');
    $this->post('/tienda/pedidos/'.$order->getKey().'/postventa', ['reason' => 'Las hojas salieron mal impresas.'])->assertSessionHasNoErrors();
    $return = ReturnRequest::where('order_id', (string) $order->getKey())->firstOrFail();
    $this->actingAs($this->seller)->post('/tienda/postventa/'.$return->getKey().'/resolver', ['decision' => 'approved', 'resolution_notes' => 'Se reimprime sin costo.'])->assertSessionHasNoErrors();
    expect($order->fresh()->status)->toBe('return_approved')->and($order->fresh()->payment_status)->toBe('refund_pending');
    $this->post('/tienda/postventa/'.$return->getKey().'/resolver', ['decision' => 'rejected', 'resolution_notes' => 'Otra vez'])->assertSessionHasErrors('marketplace');
    $this->get('/tienda/vendedor')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Marketplace/SellerDashboard')
        ->where('stats.orders', Order::where('business_id', (string) $business->getKey())->count())
        ->where('stats.cancelled', 0)->where('stats.paid_sales', 0));
});

test('the seller sales report filters by period and status, exports the same rows as CSV and matches the database', function () {
    $business = marketplaceStore($this);
    $product = marketplaceProduct($this, $business);
    $orders = [];
    foreach (['pedido-reporte-1', 'pedido-reporte-2'] as $i => $key) {
        $this->actingAs($this->buyer)->post('/tienda/carrito', ['product_id' => (string) $product->getKey(), 'quantity' => $i + 1])->assertSessionHasNoErrors();
        $orders[] = marketplaceCheckout($this, $key);
    }
    $orders[0]->update(['payment_status' => 'paid', 'status' => 'confirmed']);
    $today = now(config('app.timezone'))->toDateString();
    $this->actingAs($this->seller)->get('/tienda/vendedor')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.hasta', $today)->where('stats.orders', 2)->where('stats.paid_sales', 25.5)->where('stats.pending', 1));
    $this->get('/tienda/vendedor?estado=confirmed&desde='.$today.'&hasta='.$today)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.estado', 'confirmed')->where('stats.orders', Order::where('business_id', (string) $business->getKey())->where('status', 'confirmed')->count()));
    $this->get('/tienda/vendedor?desde=2020-01-01&hasta=2020-01-31')->assertInertia(fn (Assert $page) => $page->where('stats.orders', 0));
    $this->get('/tienda/vendedor?desde=2026-12-31&hasta=2026-01-01')->assertSessionHasErrors(['hasta' => 'La fecha final debe ser igual o posterior a la inicial.']);
    $this->get('/tienda/vendedor?estado=inventado')->assertSessionHasErrors('estado');
    $csv = $this->get('/tienda/vendedor/reporte.csv?desde='.$today.'&hasta='.$today)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
    expect(substr_count(trim($csv), "\n"))->toBe(2)
        ->and($csv)->toContain('folio,fecha,negocio,estado,estado_pago,metodo_pago,total_cents')
        ->and($csv)->toContain($orders[0]->folio)->and($csv)->toContain(',confirmed,paid,wallet,2550')
        ->and($csv)->toContain(',awaiting_payment,pending,wallet,5100');
    $this->actingAs($this->stranger)->get('/tienda/vendedor/reporte.csv')->assertOk();
    expect(substr_count(trim($this->get('/tienda/vendedor/reporte.csv')->getContent()), "\n"))->toBe(0);
});
