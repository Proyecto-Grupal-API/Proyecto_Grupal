<?php

use App\Models\Marketplace\Business;
use App\Models\Marketplace\BusinessApplication;
use App\Models\Marketplace\Cart;
use App\Models\Marketplace\Order;
use App\Models\Marketplace\Product;
use App\Models\Marketplace\Storefront;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\RolOrganizacion;
use App\Models\ServiceClient;
use App\Models\User;
use App\Services\Marketplace\MarketplaceIntegration;
use App\Services\Marketplace\MarketplaceOrders;
use App\Services\OAuthTokenService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['marketplace.identidad' => ['url' => 'http://identity.test', 'client_id' => 'marketplace-test', 'client_secret' => 'secret'],
        'marketplace.comunidad' => ['url' => 'http://community.test', 'client_id' => 'community-test', 'client_secret' => 'secret'],
        'marketplace.inventory.url' => 'http://inventory.test', 'marketplace.inventory.secret' => 'inventory-secret']);
    Http::preventStrayRequests();
});

it('uses active E1 membership and its capability check instead of stored ownership', function () {
    Http::fake([
        '*/api/oauth/token' => Http::response(['access_token' => 'fixture', 'expires_in' => 300]),
        '*/identity/assignments*' => Http::response(['subject_id' => 'user-A', 'scope_type' => 'business', 'scope_id' => 'business-A', 'membership_status' => 'revoked', 'roles' => []]),
        '*/identity/authorization/check' => Http::response(['authorized' => true]),
    ]);
    expect(app(MarketplaceIntegration::class)->allowed('user-A', 'business-A', 'business.manage'))->toBeFalse();
});

it('rejects an identity response for a different business', function () {
    Http::fake([
        '*/api/oauth/token' => Http::response(['access_token' => 'fixture', 'expires_in' => 300]),
        '*/identity/assignments*' => Http::response(['subject_id' => 'user-A', 'scope_type' => 'business', 'scope_id' => 'business-B', 'membership_status' => 'active', 'roles' => [['role_key' => 'business_owner', 'status' => 'active']]]),
        '*/identity/authorization/check' => Http::response(['authorized' => true]),
    ]);
    expect(fn () => app(MarketplaceIntegration::class)->allowed('user-A', 'business-A', 'business.manage'))->toThrow(HttpException::class);
});

it('signs the inventory business in the path and the exact transmitted body', function () {
    Http::fake(function ($request) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $timestamp = $request->header('X-Team-Timestamp')[0] ?? '';
        $signature = hash_hmac('sha256', $timestamp.'|'.$request->method().'|'.$path.'|'.hash('sha256', $request->body()), 'inventory-secret');
        expect($path)->toBe('/api/equipo4/businesses/business-A/integration/availability');
        expect($request->header('X-Team-Signature')[0])->toBe($signature);

        return Http::response(['available' => true, 'items' => []]);
    });
    expect(app(MarketplaceIntegration::class)->inventory('business-A', 'POST', 'integration/availability', ['items' => [['product_id' => 'p', 'quantity' => 1]]])['available'])->toBeTrue();
});

describe('integrated marketplace workflow', function () {
    beforeEach(function () {
        (require database_path('migrations/2026_10_14_000200_create_marketplace_indexes.php'))->up();
        $this->actor = User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]);
        $this->business = Business::create(['name' => 'Negocio A', 'slug' => 'negocio-a', 'owner_id' => (string) $this->actor->getKey(), 'type' => 'student', 'status' => 'approved', 'visibility' => 'public', 'payment_methods' => ['wallet', 'spei']]);
        $this->businessId = (string) $this->business->getKey();
        Storefront::create(['business_id' => $this->businessId, 'name' => 'Tienda A', 'published' => true]);
        $this->product = Product::create(['business_id' => $this->businessId, 'name' => 'Libreta', 'kind' => 'product', 'category' => 'Papelería', 'price' => '12.35', 'currency' => 'MXN', 'active' => true, 'available' => true, 'inventory_required' => true, 'inventory_product_id' => 'physical-A', 'inventory_location_id' => 'location-A']);
        $this->allowed = false;
        $this->capabilities = null;
        $this->organizations = [];
        $this->provisionTimeout = false;
        $this->provisionedSubjects = [];
        $this->ownerProvisioned = [];
        $this->membership = 'active';
        $this->inventoryTimeout = false;
        $this->reserved = 0;
        $this->released = 0;
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/oauth/token')) {
                return Http::response(['access_token' => 'fixture', 'expires_in' => 300]);
            }
            $d = $request->data();
            if (str_contains($request->url(), '/identity/assignments')) {
                return Http::response(['subject_id' => $d['subject_id'], 'scope_type' => 'business', 'scope_id' => $d['scope_id'], 'membership_status' => $this->membership, 'roles' => $this->membership === 'active' ? [['role_key' => 'business_owner', 'status' => 'active']] : []]);
            }
            if (str_contains($request->url(), '/identity/authorization/check')) {
                return Http::response(['authorized' => ($this->allowed && ($this->capabilities === null || in_array($d['capability'], $this->capabilities, true))) || ($this->ownerProvisioned[$d['scope_id']] ?? null) === $d['subject_id']]);
            }
            if (str_contains($request->url(), '/business-owner/provision')) {
                $this->provisionedSubjects[] = $d['subject_id'];
                if ($this->provisionTimeout) {
                    return Http::response([], 503);
                }
                $this->ownerProvisioned[$d['business_id']] = $d['subject_id'];

                return Http::response(['subject_id' => $d['subject_id'], 'business_id' => $d['business_id'], 'result' => 'provisioned'], 201);
            }
            if (str_contains($request->url(), '/organizaciones/comercio')) {
                return Http::response(['subject_id' => $d['subject_id'], 'organizations' => $this->organizations]);
            }
            if (str_ends_with($request->url(), '/integration/businesses')) {
                return Http::response(['business_id' => $d['business_id'], 'active' => true], 201);
            }
            if (str_ends_with($request->url(), '/products')) {
                return Http::response([['id' => 'physical-A', 'business_id' => $this->businessId, 'name' => 'Libreta']]);
            }
            if (str_ends_with($request->url(), '/locations')) {
                return Http::response([['id' => 'location-A', 'business_id' => $this->businessId, 'name' => 'Mostrador']]);
            }
            if (str_ends_with($request->url(), '/inventory')) {
                return Http::response([['product_id' => 'physical-A', 'location_id' => 'location-A', 'variant_id' => null, 'available' => 10, 'business_id' => $this->businessId]]);
            }
            if (str_ends_with($request->url(), '/integration/reservations')) {
                $this->reserved++;
                if ($this->inventoryTimeout) {
                    return Http::failedConnection();
                }

return Http::response(['reservation_id' => 'reservation-A', 'status' => 'RESERVED'], 201);
            }
            if (str_ends_with($request->url(), '/release')) {
                $this->released++;

                return Http::response(['reservation_id' => 'reservation-A', 'status' => 'RELEASED']);
            }
            if (str_ends_with($request->url(), '/reservation-status')) {
                return Http::response(['state' => 'completed', 'reservation_id' => 'reservation-A', 'status' => 'RESERVED']);
            }
            throw new RuntimeException('Petición no esperada: '.$request->url());
        });
        $this->actingAs($this->actor);
    });
    function marketplaceCheckoutBody($test): array
    {
        $cart = Cart::where('user_id', (string) $test->actor->getKey())->first();

        return ['cart_id' => (string) $cart->getKey(), 'cart_version' => $cart->version, 'payment_method' => 'wallet', 'delivery_point' => 'Biblioteca', 'notes' => '', 'idempotency_key' => 'checkout-fixture-unique'];
    }
    it('creates one pending order and reserves real stock without charging', function () {
        $this->post('/tienda/carrito', ['product_id' => (string) $this->product->getKey(), 'quantity' => 2])->assertSessionHasNoErrors();
        $body = marketplaceCheckoutBody($this);
        $this->post('/tienda/checkout', $body)->assertRedirect('/tienda/pedidos')->assertSessionHasNoErrors();
        $order = Order::first();
        expect($order->status)->toBe('awaiting_payment')->and($order->payment_status)->toBe('pending')->and($order->total_cents)->toBe(2470)->and($order->reservation_id)->toBe('reservation-A');
        expect($this->reserved)->toBe(1);
        $this->post('/tienda/checkout', $body)->assertRedirect('/tienda/pedidos');
        expect(Order::count())->toBe(1)->and($this->reserved)->toBe(1);
    });
    it('does not authorize edits from the local owner id when E1 denies them', function () {
        $this->deleteJson('/tienda/productos/'.$this->product->getKey())->assertForbidden();
        expect($this->product->fresh()->active)->toBeTrue();
    });
    it('keeps unknown inventory outcomes and recovers without reserving twice', function () {
        $this->post('/tienda/carrito', ['product_id' => (string) $this->product->getKey(), 'quantity' => 1]);
        $this->inventoryTimeout = true;
        $this->post('/tienda/checkout', marketplaceCheckoutBody($this))->assertRedirect('/tienda/pedidos');
        $order = Order::first();
        expect($order->inventory_state)->toBe('unknown')->and($order->payment_status)->toBe('pending');
        app(MarketplaceOrders::class)->reconcile($order);
        expect($order->fresh()->status)->toBe('awaiting_payment')->and($order->fresh()->reservation_id)->toBe('reservation-A')->and($this->reserved)->toBe(1);
    });
    it('lets the buyer cancel and releases the reservation once', function () {
        $this->post('/tienda/carrito', ['product_id' => (string) $this->product->getKey(), 'quantity' => 1]);
        $this->post('/tienda/checkout', marketplaceCheckoutBody($this));
        $order = Order::first();
        $this->post('/tienda/pedidos/'.$order->getKey().'/estado', ['status' => 'cancelled'])->assertSessionHasNoErrors();
        expect($order->fresh()->status)->toBe('cancelled')->and($order->fresh()->inventory_state)->toBe('released')->and($this->released)->toBe(1);
        $this->post('/tienda/pedidos/'.$order->getKey().'/estado', ['status' => 'cancelled'])->assertSessionHasNoErrors();
        expect($this->released)->toBe(1);
    });
    it('renders the imported marketplace sections in the current application', function (string $path, string $page) {
        $this->get($path)->assertOk()->assertInertia(fn (AssertableInertia $a) => $a->component('Marketplace/'.$page));
    })->with([['/tienda', 'Index'], ['/tienda/negocios', 'Businesses'], ['/tienda/productos', 'Products'], ['/tienda/solicitudes', 'Applications'],
        ['/tienda/pedidos', 'Orders'], ['/tienda/vendedor', 'SellerDashboard'], ['/tienda/vendedor/pedidos', 'SellerOrders'], ['/tienda/postventa', 'Returns'], ['/tienda/auditoria', 'AuditLog']]);
    it('rechecks E1 on the following request and does not give managers owner configuration', function () {
        $this->allowed = true;
        $this->capabilities = ['business.inventory.operate', 'business.operate', 'business.sales.operate'];
        $this->get('/tienda/productos')->assertOk()->assertInertia(fn (AssertableInertia $a) => $a->has('managedBusinesses', 1));
        $this->putJson('/tienda/negocios/'.$this->businessId, [])->assertForbidden();
        $this->membership = 'revoked';
        $this->deleteJson('/tienda/productos/'.$this->product->getKey())->assertForbidden();
    });
    it('rejects physical catalog links that do not exist in that business inventory', function () {
        $this->allowed = true;
        $payload = ['business_id' => $this->businessId, 'name' => 'Otro artículo', 'kind' => 'product', 'category' => 'General', 'price' => '15.00', 'currency' => 'MXN',
            'active' => true, 'available' => true, 'inventory_required' => true, 'inventory_product_id' => 'another-business-product', 'inventory_location_id' => 'location-A'];
        $this->postJson('/tienda/productos', $payload)->assertUnprocessable();
        expect(Product::count())->toBe(1);
    });
    it('does not let a physical product bypass real inventory reservations', function () {
        $this->allowed = true;
        $this->postJson('/tienda/productos', ['business_id' => $this->businessId, 'name' => 'Producto sin stock', 'kind' => 'product', 'category' => 'General', 'price' => '12.00', 'currency' => 'MXN', 'active' => true, 'available' => true, 'inventory_required' => false])->assertUnprocessable();
        expect(Product::count())->toBe(1);
    });
    it('validates product currency on updates and audits the active state transition', function () {
        $this->allowed = true;
        $id = (string) $this->product->getKey();
        $payload = ['name'=>'Libreta editada','category'=>'Papelería','kind'=>'product','price'=>'15.00','currency'=>'USD',
            'active'=>false,'available'=>false,'inventory_required'=>true,'inventory_product_id'=>'physical-A','inventory_location_id'=>'location-A'];
        $this->putJson('/tienda/productos/'.$id, $payload)->assertUnprocessable();
        expect($this->product->fresh()->active)->toBeTrue();
        $this->putJson('/tienda/productos/'.$id, [...$payload,'currency'=>'MXN'])->assertRedirect();
        $event=\App\Models\Marketplace\AuditEvent::where('action','product_updated')->firstOrFail();
        expect($event->from_status)->toBe('active')->and($event->to_status)->toBe('inactive')
            ->and($event->actor_id)->toBe((string)$this->actor->getKey());
    });
    it('accepts a service without stock and still leaves its payment pending', function () {
        $this->product->update(['kind' => 'service', 'inventory_required' => false]);
        $this->post('/tienda/carrito', ['product_id' => (string) $this->product->getKey(), 'quantity' => 1]);
        $this->post('/tienda/checkout', marketplaceCheckoutBody($this))->assertRedirect('/tienda/pedidos');
        $order = Order::first();
        expect($order->inventory_state)->toBe('not_required')->and($order->status)->toBe('awaiting_payment')->and($order->payment_status)->toBe('pending')->and($this->reserved)->toBe(0);
    });
    it('requires a published storefront and refuses to mix businesses in a cart', function () {
        $this->post('/tienda/carrito', ['product_id' => (string) $this->product->getKey(), 'quantity' => 1]);
        $other = Business::create(['name' => 'B', 'slug' => 'b', 'status' => 'approved', 'visibility' => 'public']);
        Storefront::create(['business_id' => (string) $other->getKey(), 'published' => true]);
        $p = Product::create(['business_id' => (string) $other->getKey(), 'name' => 'Servicio B', 'kind' => 'service', 'price' => '5.00', 'active' => true, 'available' => true, 'inventory_required' => false]);
        $this->postJson('/tienda/carrito', ['product_id' => (string) $p->getKey(), 'quantity' => 1])->assertUnprocessable();
        Storefront::where('business_id', $this->businessId)->update(['published' => false]);
        $this->postJson('/tienda/checkout', marketplaceCheckoutBody($this))->assertUnprocessable();
        expect(Order::count())->toBe(0)->and($this->reserved)->toBe(0);
    });
    it('requires the buyer to accept a price change through a refreshed cart', function () {
        $this->post('/tienda/carrito', ['product_id' => (string) $this->product->getKey(), 'quantity' => 1]);
        $body = marketplaceCheckoutBody($this);
        $this->product->update(['price' => '20.00']);
        $this->postJson('/tienda/checkout', $body)->assertUnprocessable();
        expect(Order::count())->toBe(0)->and($this->reserved)->toBe(0);
        $this->put('/tienda/carrito', ['items' => [['product_id' => (string) $this->product->getKey(), 'quantity' => 1]]])->assertSessionHasNoErrors();
        $this->post('/tienda/checkout', marketplaceCheckoutBody($this))->assertRedirect('/tienda/pedidos');
        expect(Order::first()->total_cents)->toBe(2000);
    });
    it('rejects reusing a checkout key with changed details after cart deletion', function () {
        $this->post('/tienda/carrito', ['product_id' => (string) $this->product->getKey(), 'quantity' => 1]);
        $body = marketplaceCheckoutBody($this);
        $this->post('/tienda/checkout', $body);
        $body['notes'] = 'Different purchase';
        $this->postJson('/tienda/checkout', $body)->assertConflict();
        expect(Order::count())->toBe(1)->and($this->reserved)->toBe(1);
    });
    it('expires and releases stock through the scheduler without creating a payment', function () {
        $this->post('/tienda/carrito', ['product_id' => (string) $this->product->getKey(), 'quantity' => 1]);
        $this->post('/tienda/checkout', marketplaceCheckoutBody($this));
        $order = Order::first();
        $this->travel(16)->minutes();
        $this->artisan('marketplace:reconciliar')->assertExitCode(0);
        $this->travelBack();
        expect($order->fresh()->status)->toBe('expired')->and($order->fresh()->inventory_state)->toBe('released')->and($order->fresh()->payment_status)->toBe('pending')->and($this->released)->toBe(1);
    });
    it('never validates money from evidence or allows unpaid preparation', function () {
        $this->allowed = true;
        $this->post('/tienda/carrito', ['product_id' => (string) $this->product->getKey(), 'quantity' => 1]);
        $body = marketplaceCheckoutBody($this);
        $body['payment_method'] = 'spei';
        $this->post('/tienda/checkout', $body);
        $order = Order::first();
        $this->post('/tienda/pedidos/'.$order->getKey().'/comprobante', ['evidence_url' => 'https://example.test/proof.png'])->assertSessionHasNoErrors();
        $this->postJson('/tienda/pedidos/'.$order->getKey().'/resolver-pago', ['decision' => 'approved', 'notes' => 'Verified'])->assertStatus(503);
        $this->postJson('/tienda/pedidos/'.$order->getKey().'/estado', ['status' => 'preparing'])->assertUnprocessable();
        expect($order->fresh()->payment_status)->toBe('pending')->and($order->fresh()->status)->toBe('awaiting_payment');
    });
    it('does not allow another buyer to cancel a pending order', function () {
        $this->post('/tienda/carrito', ['product_id' => (string) $this->product->getKey(), 'quantity' => 1]);
        $this->post('/tienda/checkout', marketplaceCheckoutBody($this));
        $order = Order::first();
        $this->actingAs(User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]));
        $this->postJson('/tienda/pedidos/'.$order->getKey().'/estado', ['status' => 'cancelled'])->assertForbidden();
        expect($this->released)->toBe(0);
    });
    function marketplaceReviewer($test): User
    {
        (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
        $test->seed(RoleSeeder::class);
        $reviewer = withConfirmedTestTwoFactor(User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]));
        $reviewer->assignRole('admin');

        return $reviewer;
    }
    it('provisions the original applicant rather than the reviewer and approves once', function () {
        $applicant = (string) $this->actor->getKey();
        $this->post('/tienda/solicitudes', ['name' => 'Negocio aprobado', 'type' => 'student', 'description' => 'Solicitud completa'])->assertSessionHasNoErrors();
        $application = BusinessApplication::first();
        $this->actingAs(marketplaceReviewer($this));
        $this->post('/tienda/solicitudes/'.$application->getKey().'/resolver', ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->post('/tienda/solicitudes/'.$application->getKey().'/resolver', ['decision' => 'approved'])->assertSessionHasNoErrors();
        expect($application->fresh()->status)->toBe('approved')->and($this->provisionedSubjects)->toBe([$applicant]);
        $business = Business::find($application->fresh()->business_id);
        expect($business->owner_id)->toBe($applicant)->and($business->inventory_ready)->toBeTrue();
        expect(Storefront::where('business_id', (string) $business->getKey())->first()->published)->toBeFalse();
    });
    it('keeps failed provisioning retryable without publishing or changing its owner', function () {
        $applicant = (string) $this->actor->getKey();
        $this->post('/tienda/solicitudes', ['name' => 'Negocio pendiente', 'type' => 'student', 'description' => 'Solicitud completa']);
        $application = BusinessApplication::first();
        $this->actingAs(marketplaceReviewer($this));
        $this->provisionTimeout = true;
        $this->post('/tienda/solicitudes/'.$application->getKey().'/resolver', ['decision' => 'approved'])->assertSessionHasErrors('marketplace');
        expect($application->fresh()->status)->toBe('provisioning');
        $id = $application->fresh()->business_id;
        $this->provisionTimeout = false;
        $this->post('/tienda/solicitudes/'.$application->getKey().'/resolver', ['decision' => 'approved'])->assertSessionHasNoErrors();
        expect($application->fresh()->business_id)->toBe($id)->and($application->fresh()->status)->toBe('approved')->and(Business::count())->toBe(2);
        expect(Business::find($id)->owner_id)->toBe($applicant);
    });
    it('requires current Community authority to submit and approve an association store', function () {
        $body = ['name' => 'Tienda de asociación', 'type' => 'association', 'description' => 'Solicitud completa', 'organization_id' => 'organization-A'];
        $this->postJson('/tienda/solicitudes', $body)->assertForbidden();
        $this->organizations = [['id' => 'organization-A', 'name' => 'Asociación', 'can_request_store' => true, 'type' => 'association']];
        $this->post('/tienda/solicitudes', $body)->assertSessionHasNoErrors();
        $application = BusinessApplication::first();
        $this->organizations = [];
        $this->actingAs(marketplaceReviewer($this));
        $this->postJson('/tienda/solicitudes/'.$application->getKey().'/resolver', ['decision' => 'approved'])->assertForbidden();
        expect($application->fresh()->status)->toBe('pending')->and($this->provisionedSubjects)->toBe([]);
    });
    it('does not allow a global reviewer to edit products without business authority', function () {
        $this->actingAs(marketplaceReviewer($this));
        $this->deleteJson('/tienda/productos/'.$this->product->getKey())->assertForbidden();
        $this->get('/tienda/vendedor/pedidos')->assertInertia(fn (AssertableInertia $a) => $a->has('orders.data', 0));
    });
    it('exposes only a scoped public catalog and revalidates service client revocation', function () {
        $client = ServiceClient::create(['client_id' => 'marketplace-consumer', 'name' => 'Catalog consumer', 'secret_hash' => Hash::make('secret'), 'active' => true, 'scopes' => ['marketplace:read']]);
        $token = app(OAuthTokenService::class)->issue('marketplace-consumer', 'secret', ['marketplace:read'])['access_token'];
        $this->getJson('/api/v1/marketplace/catalog')->assertUnauthorized();
        $this->withToken($token)->getJson('/api/v1/marketplace/catalog')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.inventory_product_id');
        $this->business->update(['status' => 'suspended']);
        $this->getJson('/api/v1/marketplace/catalog')->assertOk()->assertJsonCount(0, 'data');
        $client->update(['active' => false]);
        $this->getJson('/api/v1/marketplace/catalog')->assertUnauthorized();
    });

});

it('uses prefixed marketplace collections and stable string ids in listings', function () {
    $b = Business::create(['name' => 'Fixture', 'slug' => 'fixture', 'status' => 'approved', 'visibility' => 'public']);
    Storefront::create(['business_id' => (string) $b->getKey(), 'published' => true]);
    expect($b->getTable())->toBe('marketplace_businesses');
    expect(Business::pluck('id')->map(fn ($id) => (string) $id)->all())->toBe([(string) $b->getKey()]);
    expect(Business::whereIn('_id', [(string) $b->getKey()])->count())->toBe(1);
});

it('uses actual Community membership and presidency validity for association commerce', function () {
    $subject = User::factory()->create();
    $id = (string) $subject->getKey();
    $organization = Organizacion::create(['slug' => 'org-commerce', 'nombre' => 'Asociación Sistemas', 'tipo' => 'asociacion', 'estado' => 'activa']);
    $member = MiembroOrganizacion::create(['organizacion_id' => (string) $organization->getKey(), 'usuario_id' => $id, 'fecha_inicio' => now()->subDay(), 'estado' => 'activo']);
    $role = RolOrganizacion::create(['organizacion_id' => (string) $organization->getKey(), 'usuario_id' => $id, 'slug_rol' => 'presidencia', 'fecha_inicio' => now()->subDay(), 'fecha_fin' => now()->addDay()]);
    ServiceClient::create(['client_id' => 'community-commerce', 'name' => 'E3 fixture', 'secret_hash' => Hash::make('secret'), 'active' => true, 'scopes' => ['community:organizations:read']]);
    $token = app(OAuthTokenService::class)->issue('community-commerce', 'secret', ['community:organizations:read'])['access_token'];
    $path = '/api/v1/comunidad/organizaciones/comercio?subject_id='.$id;
    $this->withToken($token)->getJson($path)->assertOk()->assertJsonPath('subject_id', $id)->assertJsonCount(1, 'organizations')->assertJsonPath('organizations.0.id', (string) $organization->getKey());
    $role->update(['fecha_fin' => now()->subDays(2)]);
    $this->getJson($path)->assertOk()->assertJsonCount(0,'organizations');
    $role->update(['fecha_fin' => now()->addDay()]);
    $member->update(['estado' => 'inactivo']);
    $this->getJson($path)->assertOk()->assertJsonCount(0,'organizations');
    $member->update(['estado' => 'activo']);
    $organization->update(['estado' => 'suspendida']);
    $this->getJson($path)->assertOk()->assertJsonCount(0,'organizations');
});
