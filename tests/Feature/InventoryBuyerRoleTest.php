<?php

use App\Models\InventoryModule\InventoryBusiness;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\InventoryModule\BusinessContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Rol comprador (diseño §2: «Comprador / abastecimiento: gestiona proveedores, órdenes de compra, recepciones y
 * devoluciones a proveedor»). Identidad le publica una sola capacidad, business.purchasing.operate, e Inventarios
 * la traduce a sus permisos de compras. No puede autorizar lo que solicita ni tocar existencias.
 */
beforeEach(function () {
    config(['team4.business_id' => 'business-A', 'team4.integration.business_id' => 'business-A',
        'team4.identidad' => ['url' => 'http://identity.test', 'client_id' => 'svc_inventory_test', 'client_secret' => 'test-secret']]);
    InventoryBusiness::create(['business_id' => 'business-A', 'name' => 'Negocio A', 'active' => true]);
    (require database_path('migrations/2026_10_14_000000_create_inventory_domain_indexes.php'))->up();
    Artisan::call('team4:init-indexes');
    Artisan::call('inventario:demo');
    $this->actor = User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]);
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/api/oauth/token')) {
            return Http::response(['access_token' => 'test-token', 'expires_in' => 300]);
        }
        $data = $request->data();
        if (str_contains($request->url(), '/identity/assignments')) {
            return Http::response(['subject_id' => $data['subject_id'], 'scope_type' => 'business', 'scope_id' => $data['scope_id'],
                'membership_status' => 'active', 'roles' => [['role_key' => 'buyer', 'status' => 'active']]]);
        }
        if (str_contains($request->url(), '/identity/authorization/check')) {
            // Lo que Identidad concede hoy al comprador, tomado de su propio catálogo.
            return Http::response(['authorized' => in_array($data['capability'], RolePermission::MAPPINGS['buyer'] ?? [], true)]);
        }
        throw new RuntimeException('Petición externa no esperada: '.$request->url());
    });
    app(BusinessContext::class)->select('business-A');
});

it('publishes exactly one purchasing capability for the buyer role', function () {
    expect(RolePermission::MAPPINGS['buyer'])->toBe(['business.purchasing.operate'])
        ->and(\App\Models\Permission::CATALOG['business.purchasing.operate'][1])->toBe('business');
});

it('lets a buyer work on purchasing with the capability published by Identity', function () {
    $this->actingAs($this->actor)->get('/equipo4/proveedores')->assertOk()->assertInertia(fn (Assert $a) => $a
        ->where('inventoryContext.user.role', 'buyer')
        ->where('permissions', fn ($p) => collect($p)->contains('proveedores.create') && collect($p)->contains('compras.create')
            && collect($p)->contains('recepciones.create') && collect($p)->contains('devoluciones.storeSupplier')
            && ! collect($p)->contains('compras.authorize') && ! collect($p)->contains('inventario.adjust')
            && ! collect($p)->contains('productos.create') && ! collect($p)->contains('conteos.close')));
    $this->from('/equipo4/proveedores')->post('/equipo4/proveedores', ['code' => 'PRV-COMPRADOR', 'legal_name' => 'Proveedor del comprador',
        'contact_name' => 'Contacto', 'payment_terms' => 'CONTADO', 'status' => 'ACTIVO'])
        ->assertRedirect('/equipo4/proveedores')->assertSessionHasNoErrors();
    expect(DB::connection('inventory')->getCollection('suppliers')->countDocuments(['business_id' => 'business-A', 'code' => 'PRV-COMPRADOR']))->toBe(1);
});

it('does not let a buyer authorize the purchase order he requested', function () {
    $supplier = DB::connection('inventory')->getCollection('suppliers')->findOne(['business_id' => 'business-A']);
    $product = DB::connection('inventory')->getCollection('products')->findOne(['business_id' => 'business-A', 'active' => true]);
    $order = ['supplier_id' => (string) $supplier['_id'], 'expected_at' => now()->addDays(3)->toDateString(), 'status' => 'SOLICITADA',
        'items' => [['product_id' => (string) $product['_id'], 'quantity' => 5, 'unit_cost' => 10]]];
    $this->actingAs($this->actor)->from('/equipo4/compras')->post('/equipo4/compras', $order)->assertRedirect('/equipo4/compras')->assertSessionHasNoErrors();
    $created = DB::connection('inventory')->getCollection('purchase_orders')->findOne(['business_id' => 'business-A']);
    expect($created['status'])->toBe('SOLICITADA');

    $this->postJson('/equipo4/compras', [...$order, 'status' => 'AUTORIZADA'])->assertForbidden();
    $this->patchJson('/equipo4/compras/'.(string) $created['_id'].'/status', ['action' => 'autorizar'])->assertForbidden();
    expect(DB::connection('inventory')->getCollection('purchase_orders')->findOne(['_id' => $created['_id']])['status'])->toBe('SOLICITADA');
});
