<?php

use App\Http\Middleware\EnsureRequiredTwoFactorAuthentication;
use App\Http\Middleware\EnsureSessionIsActive;
use App\Http\Middleware\TrackDeviceSession;
use App\Models\InventoryModule\InventoryBusiness;
use App\Models\Role;
use App\Models\User;
use App\Services\InventoryModule\BusinessContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use MongoDB\BSON\ObjectId;

/*
 * Requerimiento E4 (20-nov-2026): una cuenta de consulta transversal ve los inventarios de TODAS las tiendas sin poder
 * modificarlos. El permiso lo asigna E1 como cargo exacto (`admin` en el contexto service:inventario-consulta); el
 * administrador global no lo hereda. Las cuentas normales conservan el aislamiento por negocio y sus propios roles
 * mandan en su negocio (no se suman permisos). Los cambios de tienda y los intentos de escritura quedan auditados.
 */
beforeEach(function () {
    (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
    $this->seed(RoleSeeder::class);
    $this->withoutMiddleware([TrackDeviceSession::class, EnsureSessionIsActive::class, EnsureRequiredTwoFactorAuthentication::class]);
    config(['team4.business_id' => 'business-A', 'team4.integration.business_id' => 'business-A', 'team4.global_viewer_service' => 'inventario-consulta',
        'team4.identidad' => ['url' => 'http://identity.test', 'client_id' => 'svc_inventory_test', 'client_secret' => 'test-secret']]);
    InventoryBusiness::create(['business_id' => 'business-A', 'name' => 'Negocio A', 'active' => true]);
    InventoryBusiness::create(['business_id' => 'business-B', 'name' => 'Negocio B', 'active' => true]);
    (require database_path('migrations/2026_10_14_000000_create_inventory_domain_indexes.php'))->up();
    Artisan::call('team4:init-indexes');
    Artisan::call('inventario:demo');
    $make = fn () => User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]);
    $this->viewer = $make();
    $this->viewer->assignRole(Role::ADMIN, 'service', 'inventario-consulta');
    $this->global = $make();
    $this->global->assignRole(Role::ADMIN);
    $this->plain = $make();
    // Roles de negocio que E1 publicaría: sólo business_owner en business-A para $this->ownerId.
    $this->owner = $make();
    $this->ownerId = (string) $this->owner->getKey();
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/api/oauth/token')) {
            return Http::response(['access_token' => 'test-token', 'expires_in' => 300]);
        }
        $data = $request->data();
        $owns = $data['subject_id'] === $this->ownerId && $data['scope_id'] === 'business-A';
        if (str_contains($request->url(), '/identity/assignments')) {
            return Http::response(['subject_id' => $data['subject_id'], 'scope_type' => 'business', 'scope_id' => $data['scope_id'],
                'membership_status' => $owns ? 'active' : 'none', 'roles' => $owns ? [['role_key' => 'business_owner', 'status' => 'active']] : []]);
        }
        if (str_contains($request->url(), '/identity/authorization/check')) {
            return Http::response(['authorized' => $owns && in_array($data['capability'], \App\Models\RolePermission::MAPPINGS['business_owner'], true)]);
        }
        throw new RuntimeException('Petición externa no esperada: '.$request->url());
    });
    app(BusinessContext::class)->select('business-A');
});

function gvStock(string $business): ?array
{
    $row = DB::connection('inventory')->getCollection('inventories')->findOne(['business_id' => $business, 'available' => ['$gte' => 2]]);

    return $row ? (array) $row : null;
}

function gvAudit(string $event): array
{
    return array_map(fn ($e) => (array) $e, DB::connection('inventory')->getCollection('audit_events')->find(['event' => $event])->toArray());
}

test('the global viewer reads the inventory of every store, identified by store, without write permissions', function () {
    $this->actingAs($this->viewer);
    $this->get('/equipo4/inventario')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('inventoryContext.business_id', 'business-A')
        ->where('inventoryContext.user.role_scope', 'global')
        ->where('inventoryContext.businesses', fn ($b) => collect($b)->pluck('name')->contains('Negocio B')));
    $this->post('/equipo4/context', ['business_id' => 'business-B'])->assertRedirect();
    $this->get('/equipo4/inventario')->assertOk()->assertInertia(fn (Assert $page) => $page->where('inventoryContext.business_id', 'business-B'));
    $this->get('/equipo4/kardex')->assertOk();

    $permissions = app(\App\Services\InventoryModule\IdentityService::class)->effectivePermissions((string) $this->viewer->getKey());
    expect($permissions)->toContain('inventario.view')->toContain('kardex.view')
        ->and(collect($permissions)->every(fn ($p) => str_ends_with($p, '.view')))->toBeTrue()
        ->and($permissions)->not->toContain('auditoria.view')->not->toContain('costos.view');
    expect(gvAudit('Inventory.global_viewer_context'))->toHaveCount(1)
        ->and(gvAudit('Inventory.global_viewer_context')[0]['business_id'])->toBe('business-B');
});

test('the global viewer cannot adjust or transfer stock of any store, and the attempt is audited', function () {
    $this->actingAs($this->viewer);
    $stock = gvStock('business-A');
    $this->postJson('/equipo4/inventario/adjust', ['inventory_id' => (string) $stock['_id'], 'quantity' => -1, 'reason' => 'Intento'])->assertForbidden();
    $after = (array) DB::connection('inventory')->getCollection('inventories')->findOne(['_id' => new ObjectId((string) $stock['_id'])]);
    expect((int) $after['on_hand'])->toBe((int) $stock['on_hand'])
        ->and(gvAudit('Inventory.global_viewer_denied'))->toHaveCount(1)
        ->and(gvAudit('Inventory.global_viewer_denied')[0]['action'])->toBe('inventario.adjust');
});

test('normal accounts keep their per-store isolation and the global admin does not inherit cross-store reading', function () {
    // Sin rol en ningún negocio: no entra.
    $this->actingAs($this->plain)->get('/equipo4/inventario')->assertRedirect(route('equipo4.not-authorized'));
    // El administrador global no hereda la consulta transversal.
    $this->actingAs($this->global)->get('/equipo4/inventario')->assertRedirect(route('equipo4.not-authorized'));
    // El dueño de A opera A con su rol de negocio, y no ve B.
    $this->actingAs($this->owner)->get('/equipo4/inventario')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('inventoryContext.user.role_scope', 'business'));
    $this->post('/equipo4/context', ['business_id' => 'business-B'])->assertForbidden();
});

test('an owner who is also a global viewer keeps owner rights only in their own store and read-only elsewhere', function () {
    $this->owner->assignRole(Role::ADMIN, 'service', 'inventario-consulta');
    $stock = gvStock('business-A');
    $this->actingAs($this->owner);
    $this->get('/equipo4/inventario')->assertOk()->assertInertia(fn (Assert $page) => $page->where('inventoryContext.user.role_scope', 'business'));
    $this->from('/equipo4/inventario')->post('/equipo4/inventario/adjust', ['inventory_id' => (string) $stock['_id'], 'quantity' => -1, 'reason' => 'Conteo físico'])
        ->assertSessionHasNoErrors();
    $this->post('/equipo4/context', ['business_id' => 'business-B'])->assertRedirect();
    $this->get('/equipo4/inventario')->assertOk()->assertInertia(fn (Assert $page) => $page->where('inventoryContext.user.role_scope', 'global'));
    $this->postJson('/equipo4/inventario/adjust', ['inventory_id' => (string) $stock['_id'], 'quantity' => -1, 'reason' => 'Intento'])->assertForbidden();
    $after = (array) DB::connection('inventory')->getCollection('inventories')->findOne(['_id' => new ObjectId((string) $stock['_id'])]);
    expect((int) $after['on_hand'])->toBe((int) $stock['on_hand'] - 1);
});
