<?php

use App\Models\InventoryModule\InventoryBusiness;
use App\Models\InventoryModule\StockMovement;
use App\Models\User;
use App\Services\InventoryModule\AlertGeneratorService;
use App\Services\InventoryModule\BusinessContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/*
 * Operaciones de Inventarios (E4) halladas incompletas por los recorridos de interfaz:
 * alerta descartada que reaparecía, transferencias sin pantalla, auditoría sin consulta,
 * recepción siempre en la primera ubicación y cierre de conteo aplicado a una ubicación
 * distinta de la contada. El rol comprador se prueba en InventoryBuyerRoleTest.
 */
beforeEach(function () {
    config(['team4.business_id' => 'business-A', 'team4.integration.business_id' => 'business-A',
        'team4.identidad' => ['url' => 'http://identity.test', 'client_id' => 'svc_inventory_test', 'client_secret' => 'test-secret']]);
    InventoryBusiness::create(['business_id' => 'business-A', 'name' => 'Negocio A', 'active' => true]);
    (require database_path('migrations/2026_10_14_000000_create_inventory_domain_indexes.php'))->up();
    Artisan::call('team4:init-indexes');
    Artisan::call('inventario:demo');
    $this->actor = User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]);
    $this->remoteRole = 'business_owner';
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/api/oauth/token')) {
            return Http::response(['access_token' => 'test-token', 'expires_in' => 300]);
        }
        $data = $request->data();
        if (str_contains($request->url(), '/identity/assignments')) {
            return Http::response(['subject_id' => $data['subject_id'], 'scope_type' => 'business', 'scope_id' => $data['scope_id'],
                'membership_status' => 'active', 'roles' => [['role_key' => $this->remoteRole, 'status' => 'active']]]);
        }
        if (str_contains($request->url(), '/identity/authorization/check')) {
            // Las mismas concesiones que publica Identidad en RolePermission::MAPPINGS.
            $grants = array_intersect_key(\App\Models\RolePermission::MAPPINGS,
                array_flip(['business_owner', 'business_manager', 'inventory_manager', 'buyer']));

            return Http::response(['authorized' => in_array($data['capability'], $grants[$this->remoteRole] ?? [], true)]);
        }
        throw new RuntimeException('Petición externa no esperada: '.$request->url());
    });
    app(BusinessContext::class)->select('business-A');
});

function e4Stock(int $minimum = 11): array
{
    $row = (array) DB::connection('inventory')->getCollection('inventories')
        ->findOne(['business_id' => 'business-A', 'available' => ['$gte' => $minimum]], ['sort' => ['available' => -1]]);

    return ['id' => (string) $row['_id'], 'product_id' => (string) $row['product_id'], 'location_id' => (string) $row['location_id'],
        'available' => (int) $row['available']];
}

function e4OtherLocation(string $locationId): string
{
    $other = DB::connection('inventory')->getCollection('locations')
        ->findOne(['business_id' => 'business-A', '_id' => ['$ne' => new ObjectId($locationId)]]);

    return (string) $other['_id'];
}

function e4Available(string $productId, string $locationId): int
{
    $row = DB::connection('inventory')->getCollection('inventories')
        ->findOne(['business_id' => 'business-A', 'product_id' => $productId, 'location_id' => $locationId]);

    return (int) ($row['available'] ?? 0);
}

function e4Rule(array $stock, int $reorderPoint, int $min): void
{
    DB::connection('inventory')->getCollection('reorder_rules')->deleteMany(['business_id' => 'business-A']);
    DB::connection('inventory')->getCollection('reorder_rules')->insertOne([
        'business_id' => 'business-A', 'product_id' => $stock['product_id'], 'location_id' => $stock['location_id'],
        'min_qty' => $min, 'max_qty' => $reorderPoint * 2, 'reorder_point' => $reorderPoint, 'active' => true,
        'created_at' => new UTCDateTime(now()), 'updated_at' => new UTCDateTime(now()),
    ]);
}

function e4Alerts(array $stock, ?string $status = null): array
{
    $filter = ['business_id' => 'business-A', 'product_id' => $stock['product_id'], 'location_id' => $stock['location_id']];
    if ($status !== null) {
        $filter['status'] = $status;
    }

    return DB::connection('inventory')->getCollection('stock_alerts')->find($filter)->toArray();
}

function e4Events(string $event): array
{
    return DB::connection('inventory')->getCollection('audit_events')->find(['business_id' => 'business-A', 'event' => $event])->toArray();
}

// ---------- Alertas (4.9) ----------

it('keeps a dismissed alert silenced while the same low stock condition persists', function () {
    $stock = e4Stock();
    e4Rule($stock, $stock['available'] + 5, 1);
    app(AlertGeneratorService::class)->generate();
    $alert = e4Alerts($stock, 'ACTIVE');
    expect($alert)->toHaveCount(1);

    $this->actingAs($this->actor)->from('/equipo4/alertas')
        ->post('/equipo4/alertas/'.(string) $alert[0]['_id'].'/discard', ['reason' => 'Pedido ya solicitado al proveedor'])
        ->assertRedirect('/equipo4/alertas')->assertSessionHasNoErrors();

    $stats = app(AlertGeneratorService::class)->generate();
    app(AlertGeneratorService::class)->syncOne($stock['product_id'], $stock['location_id']);

    expect(e4Alerts($stock, 'ACTIVE'))->toHaveCount(0)
        ->and(e4Alerts($stock))->toHaveCount(1)
        ->and($stats['created'])->toBe(0);
});

it('raises a new alert when the stock recovers and falls below the reorder point again', function () {
    $stock = e4Stock();
    e4Rule($stock, $stock['available'] + 5, 1);
    $generator = app(AlertGeneratorService::class);
    $generator->generate();
    $first = e4Alerts($stock, 'ACTIVE')[0];
    $this->actingAs($this->actor)->post('/equipo4/alertas/'.(string) $first['_id'].'/discard', ['reason' => 'Pedido ya solicitado']);

    // La existencia se recupera por encima del punto de reorden: el descarte deja de aplicar.
    e4Rule($stock, $stock['available'] - 5, 1);
    $generator->generate();
    expect(e4Alerts($stock, 'ACTIVE'))->toHaveCount(0);

    // Vuelve a caer: es un episodio nuevo y debe avisarse.
    e4Rule($stock, $stock['available'] + 5, 1);
    $generator->generate();
    expect(e4Alerts($stock, 'ACTIVE'))->toHaveCount(1)->and(e4Alerts($stock))->toHaveCount(2);
});

it('raises a new alert when a dismissed low stock situation becomes critical', function () {
    $stock = e4Stock();
    e4Rule($stock, $stock['available'] + 5, 1);
    $generator = app(AlertGeneratorService::class);
    $generator->generate();
    $first = e4Alerts($stock, 'ACTIVE')[0];
    expect($first['priority'])->toBe('HIGH');
    $this->actingAs($this->actor)->post('/equipo4/alertas/'.(string) $first['_id'].'/discard', ['reason' => 'Pedido ya solicitado']);

    // El mínimo queda por encima de lo disponible: la situación pasa de alta a crítica.
    e4Rule($stock, $stock['available'] + 5, $stock['available'] + 1);
    $generator->generate();
    $active = e4Alerts($stock, 'ACTIVE');
    expect($active)->toHaveCount(1)->and($active[0]['priority'])->toBe('CRITICAL');
});

it('audits who dismissed an alert and why', function () {
    $stock = e4Stock();
    e4Rule($stock, $stock['available'] + 5, 1);
    app(AlertGeneratorService::class)->generate();
    $alert = e4Alerts($stock, 'ACTIVE')[0];
    $this->actingAs($this->actor)->post('/equipo4/alertas/'.(string) $alert['_id'].'/discard', ['reason' => 'Pedido ya solicitado']);
    $events = e4Events('StockAlert.dismissed');
    expect($events)->toHaveCount(1)->and($events[0]['actor_id'])->toBe((string) $this->actor->getKey())
        ->and($events[0]['entity_id'])->toBe((string) $alert['_id'])->and($events[0]['after']['resolution_reason'])->toBe('Pedido ya solicitado');
});

// ---------- Transferencias entre ubicaciones (4.2, 4.3) ----------

it('transfers stock between two locations from the web and leaves both kardex movements', function () {
    $stock = e4Stock();
    $destination = e4OtherLocation($stock['location_id']);
    $before = e4Available($stock['product_id'], $destination);

    $this->actingAs($this->actor)->from('/equipo4/inventario')->post('/equipo4/inventario/transfer', [
        'inventory_id' => $stock['id'], 'to_location_id' => $destination, 'quantity' => 4, 'reason' => 'Surtido de mostrador',
    ])->assertRedirect('/equipo4/inventario')->assertSessionHasNoErrors();

    expect(e4Available($stock['product_id'], $stock['location_id']))->toBe($stock['available'] - 4)
        ->and(e4Available($stock['product_id'], $destination))->toBe($before + 4);
    $movements = StockMovement::where('business_id', 'business-A')->where('product_id', $stock['product_id'])->get();
    $out = $movements->firstWhere('type', 'TRANSFER_OUT');
    $in = $movements->firstWhere('type', 'TRANSFER_IN');
    expect($out->quantity)->toBe(-4)->and($out->location_id)->toBe($stock['location_id'])
        ->and($in->quantity)->toBe(4)->and($in->location_id)->toBe($destination)
        ->and($out->external_reference)->toBe($in->external_reference)->and($out->external_reference)->toStartWith('TRF-')
        ->and($out->actor_id)->toBe((string) $this->actor->getKey());
    $events = e4Events('Inventory.transferred');
    expect($events)->toHaveCount(1)->and($events[0]['after']['quantity'])->toBe(4)->and($events[0]['actor_id'])->toBe((string) $this->actor->getKey());
});

it('rejects transfers that are invalid and leaves the stock untouched', function (array $change, string $field) {
    $stock = e4Stock();
    $payload = [...['inventory_id' => $stock['id'], 'to_location_id' => e4OtherLocation($stock['location_id']),
        'quantity' => 2, 'reason' => 'Surtido de mostrador'], ...$change];
    if (($change['to_location_id'] ?? null) === 'same') {
        $payload['to_location_id'] = $stock['location_id'];
    }
    if (($change['quantity'] ?? null) === 'too-many') {
        $payload['quantity'] = $stock['available'] + 1;
    }
    $this->actingAs($this->actor)->from('/equipo4/inventario')->post('/equipo4/inventario/transfer', $payload)
        ->assertRedirect('/equipo4/inventario')->assertSessionHasErrors($field);
    expect(e4Available($stock['product_id'], $stock['location_id']))->toBe($stock['available'])
        ->and(StockMovement::where('business_id', 'business-A')->whereIn('type', ['TRANSFER_OUT', 'TRANSFER_IN'])->count())->toBe(0);
})->with([
    'same location' => [['to_location_id' => 'same'], 'to_location_id'],
    'more than available' => [['quantity' => 'too-many'], 'error'],
    'zero' => [['quantity' => 0], 'quantity'],
    'unknown destination' => [['to_location_id' => '64b000000000000000000001'], 'to_location_id'],
    'no reason' => [['reason' => ''], 'reason'],
]);

it('does not let a buyer transfer or adjust stock', function () {
    $this->remoteRole = 'buyer';
    $stock = e4Stock();
    $this->actingAs($this->actor)->postJson('/equipo4/inventario/transfer', ['inventory_id' => $stock['id'],
        'to_location_id' => e4OtherLocation($stock['location_id']), 'quantity' => 1, 'reason' => 'Intento'])->assertForbidden();
    $this->postJson('/equipo4/inventario/adjust', ['inventory_id' => $stock['id'], 'quantity' => 1, 'reason' => 'Intento'])->assertForbidden();
    expect(e4Available($stock['product_id'], $stock['location_id']))->toBe($stock['available']);
});

// ---------- Auditoría (criterio de éxito de E4: compras y proveedores auditables) ----------

it('audits purchase orders, counts and manual reservations and lets the owner query them', function () {
    $supplier = DB::connection('inventory')->getCollection('suppliers')->findOne(['business_id' => 'business-A']);
    $stock = e4Stock();
    $this->actingAs($this->actor)->post('/equipo4/compras', ['supplier_id' => (string) $supplier['_id'],
        'expected_at' => now()->addDays(3)->toDateString(), 'status' => 'SOLICITADA',
        'items' => [['product_id' => $stock['product_id'], 'quantity' => 5, 'unit_cost' => 10]]])->assertSessionHasNoErrors();
    $order = DB::connection('inventory')->getCollection('purchase_orders')->findOne(['business_id' => 'business-A']);
    $this->patch('/equipo4/compras/'.(string) $order['_id'].'/status', ['action' => 'autorizar'])->assertSessionHasNoErrors();

    $location = DB::connection('inventory')->getCollection('locations')->findOne(['_id' => new ObjectId($stock['location_id'])]);
    $this->post('/equipo4/conteos', ['warehouse_id' => (string) $location['warehouse_id']])->assertSessionHasNoErrors();
    $this->post('/equipo4/reservas', ['product_id' => $stock['product_id'], 'location_id' => $stock['location_id'], 'quantity' => 1,
        'expires_at' => now()->addDay()->format('Y-m-d H:i:s')])->assertSessionHasNoErrors();

    expect(e4Events('PurchaseOrder.created'))->toHaveCount(1)
        ->and(e4Events('PurchaseOrder.authorized'))->toHaveCount(1)
        ->and(e4Events('StockCount.created'))->toHaveCount(1)
        ->and(e4Events('StockReservation.created'))->toHaveCount(1);
    foreach (['PurchaseOrder.created', 'PurchaseOrder.authorized', 'StockCount.created', 'StockReservation.created'] as $event) {
        expect(e4Events($event)[0]['actor_id'])->toBe((string) $this->actor->getKey());
    }

    $this->get('/equipo4/auditoria?event=PurchaseOrder')->assertOk()->assertInertia(fn (Assert $a) => $a
        ->component('Equipo4/Auditoria')->has('events', 2)
        ->where('events.0.event', 'PurchaseOrder.authorized')->where('events.0.actor_name', $this->actor->name)
        ->where('events.1.event', 'PurchaseOrder.created')->where('events.1.entity_label', (string) $order['folio']));
});

it('keeps the audit trail of one business away from another and from staff without that permission', function () {
    DB::connection('inventory')->getCollection('audit_events')->insertOne(['event' => 'Product.created', 'business_id' => 'business-B',
        'actor_id' => 'someone', 'entity_type' => 'Product', 'entity_id' => 'x', 'created_at' => new UTCDateTime(now())]);
    $this->actingAs($this->actor)->get('/equipo4/auditoria')->assertOk()->assertInertia(fn (Assert $a) => $a
        ->where('events', fn ($events) => collect($events)->every(fn ($e) => $e['event'] !== 'Product.created' || $e['actor_id'] !== 'someone')));
    $this->remoteRole = 'inventory_manager';
    $this->get('/equipo4/auditoria')->assertForbidden();
});

// ---------- Recepción en la ubicación elegida (4.6) ----------

it('receives a purchase order into the location chosen by the receiver', function () {
    $supplier = DB::connection('inventory')->getCollection('suppliers')->findOne(['business_id' => 'business-A']);
    $stock = e4Stock();
    $first = (string) DB::connection('inventory')->getCollection('locations')->findOne(['business_id' => 'business-A'])['_id'];
    $chosen = e4OtherLocation($first);
    $beforeChosen = e4Available($stock['product_id'], $chosen);
    $beforeFirst = e4Available($stock['product_id'], $first);
    $this->actingAs($this->actor)->post('/equipo4/compras', ['supplier_id' => (string) $supplier['_id'],
        'expected_at' => now()->addDays(3)->toDateString(), 'status' => 'SOLICITADA',
        'items' => [['product_id' => $stock['product_id'], 'quantity' => 6, 'unit_cost' => 10]]]);
    $order = DB::connection('inventory')->getCollection('purchase_orders')->findOne(['business_id' => 'business-A']);
    $this->patch('/equipo4/compras/'.(string) $order['_id'].'/status', ['action' => 'autorizar']);
    $line = DB::connection('inventory')->getCollection('purchase_order_items')->findOne(['purchase_order_id' => (string) $order['_id']]);

    $this->from('/equipo4/recepciones')->post('/equipo4/recepciones', ['purchase_order_id' => (string) $order['_id'], 'location_id' => $chosen,
        'items' => [['purchase_order_item_id' => (string) $line['_id'], 'quantity' => 4]]])->assertRedirect('/equipo4/recepciones')->assertSessionHasNoErrors();

    expect(e4Available($stock['product_id'], $chosen))->toBe($beforeChosen + 4)
        ->and(e4Available($stock['product_id'], $first))->toBe($beforeFirst);
    $this->from('/equipo4/recepciones')->post('/equipo4/recepciones', ['purchase_order_id' => (string) $order['_id'],
        'location_id' => '64b000000000000000000001', 'items' => [['purchase_order_item_id' => (string) $line['_id'], 'quantity' => 1]]])
        ->assertSessionHasErrors('location_id');
});

// ---------- Cierre de conteo en la ubicación contada (4.10) ----------

it('applies a count difference to the counted location and leaves uncounted rows alone', function () {
    $product = null;
    foreach (DB::connection('inventory')->getCollection('inventories')->aggregate([
        ['$match' => ['business_id' => 'business-A']],
        ['$group' => ['_id' => '$product_id', 'rows' => ['$push' => ['location_id' => '$location_id', 'available' => '$available']], 'n' => ['$sum' => 1]]],
        ['$match' => ['n' => ['$gte' => 2]]]]) as $candidate) {
        $product = $candidate;
        break;
    }
    expect($product)->not->toBeNull();
    $rows = collect($product['rows'])->map(fn ($r) => ['location_id' => (string) $r['location_id'], 'available' => (int) $r['available']]);
    // Se cuenta la segunda ubicación del producto, no la primera.
    $counted = $rows[1];
    $untouched = $rows[0];
    $location = DB::connection('inventory')->getCollection('locations')->findOne(['_id' => new ObjectId($counted['location_id'])]);

    $this->actingAs($this->actor)->post('/equipo4/conteos', ['warehouse_id' => (string) $location['warehouse_id']])->assertSessionHasNoErrors();
    $count = DB::connection('inventory')->getCollection('stock_counts')->findOne(['business_id' => 'business-A']);
    $items = DB::connection('inventory')->getCollection('stock_count_items')->find(['stock_count_id' => (string) $count['_id']])->toArray();
    $item = collect($items)->first(fn ($i) => (string) $i['product_id'] === (string) $product['_id'] && (string) ($i['location_id'] ?? '') === $counted['location_id']);
    expect($item)->not->toBeNull();

    $this->getJson('/equipo4/conteos/'.(string) $count['_id'].'/details')->assertOk()->assertJsonPath('items.0.captured', false)
        ->assertJsonStructure(['items' => [['location_name', 'counted_qty', 'captured']]]);
    $this->post('/equipo4/conteos/'.(string) $count['_id'].'/capture', ['items' => [['item_id' => (string) $item['_id'], 'counted_qty' => $counted['available'] + 3]]])
        ->assertSessionHasNoErrors();
    $otherRows = DB::connection('inventory')->getCollection('inventories')->find(['business_id' => 'business-A'])->toArray();
    $this->post('/equipo4/conteos/'.(string) $count['_id'].'/close')->assertSessionHasNoErrors();

    expect(e4Available((string) $product['_id'], $counted['location_id']))->toBe($counted['available'] + 3)
        ->and(e4Available((string) $product['_id'], $untouched['location_id']))->toBe($untouched['available']);
    foreach ($otherRows as $row) {
        if ((string) $row['product_id'] === (string) $product['_id'] && (string) $row['location_id'] === $counted['location_id']) {
            continue;
        }
        expect(e4Available((string) $row['product_id'], (string) $row['location_id']))->toBe((int) $row['available']);
    }
    expect(e4Events('StockCount.closed'))->toHaveCount(1);
});

// ---------- Estado de existencias tras un ajuste (observación de E4, 20-nov-2026) ----------
// El estado se calculaba con el signo del ajuste: cualquier salida (10 → 9) quedaba «OUT_OF_STOCK». Debe reflejar la
// existencia disponible resultante; la pantalla distingue «todo reservado» (hay existencia física pero nada disponible).

function e4SetStock(string $id, int $onHand, int $reserved, string $status = 'AVAILABLE'): void
{
    DB::connection('inventory')->getCollection('inventories')->updateOne(['_id' => new ObjectId($id)],
        ['$set' => ['on_hand' => $onHand, 'reserved' => $reserved, 'available' => $onHand - $reserved, 'status' => $status]]);
}

function e4Row(string $id): array
{
    return (array) DB::connection('inventory')->getCollection('inventories')->findOne(['_id' => new ObjectId($id)]);
}

function e4ShownStatus($test, string $id): ?string
{
    $shown = null;
    $test->get('/equipo4/inventario?per_page=500')->assertOk()->assertInertia(function (Assert $page) use ($id, &$shown) {
        $shown = collect($page->toArray()['props']['inventory'])->firstWhere('_id', $id)['status'] ?? null;
    });

    return $shown;
}

it('keeps the stock status in line with what is available after negative and positive adjustments', function () {
    $stock = e4Stock();
    e4SetStock($stock['id'], 10, 0);
    $this->actingAs($this->actor);
    $adjust = fn (int $qty) => $this->from('/equipo4/inventario')->post('/equipo4/inventario/adjust',
        ['inventory_id' => $stock['id'], 'quantity' => $qty, 'reason' => 'Conteo físico'])->assertSessionHasNoErrors();

    foreach ([[-1, 9, 'AVAILABLE'], [-2, 7, 'AVAILABLE'], [-7, 0, 'OUT_OF_STOCK'], [3, 3, 'AVAILABLE']] as [$qty, $available, $status]) {
        $adjust($qty);
        $row = e4Row($stock['id']);
        expect((int) $row['available'])->toBe($available)->and($row['status'])->toBe($status)
            ->and(e4ShownStatus($this, $stock['id']))->toBe($status);
    }
    // El kardex conserva cada movimiento con su cantidad.
    expect(StockMovement::where('business_id', 'business-A')->where('product_id', $stock['product_id'])->where('location_id', $stock['location_id'])
        ->where('type', 'ADJUSTMENT')->orderBy('_id')->get()->map(fn ($m) => (int) $m->quantity)->take(-4)->values()->all())->toBe([-1, -2, -7, 3]);
});

it('shows a fully reserved product as not available even though physical stock remains, and refuses to adjust into reservations', function () {
    $stock = e4Stock();
    $this->actingAs($this->actor);
    // Existencia 10 con 4 reservadas: la salida de 6 deja 4 físicas, todas reservadas.
    e4SetStock($stock['id'], 10, 4);
    $this->from('/equipo4/inventario')->post('/equipo4/inventario/adjust',
        ['inventory_id' => $stock['id'], 'quantity' => -6, 'reason' => 'Merma'])->assertSessionHasNoErrors();
    $row = e4Row($stock['id']);
    expect((int) $row['on_hand'])->toBe(4)->and((int) $row['available'])->toBe(0)
        ->and(e4ShownStatus($this, $stock['id']))->toBe('RESERVED');

    // Una salida que invadiría lo reservado se rechaza y no cambia nada.
    $this->from('/equipo4/inventario')->post('/equipo4/inventario/adjust',
        ['inventory_id' => $stock['id'], 'quantity' => -1, 'reason' => 'Merma'])->assertSessionHasErrors();
    expect((int) e4Row($stock['id'])['on_hand'])->toBe(4);

    // Un estado guardado desactualizado (las reservas no lo recalculan) no engaña a la pantalla.
    e4SetStock($stock['id'], 5, 5, 'AVAILABLE');
    expect(e4ShownStatus($this, $stock['id']))->toBe('RESERVED');
    e4SetStock($stock['id'], 5, 0, 'OUT_OF_STOCK');
    expect(e4ShownStatus($this, $stock['id']))->toBe('AVAILABLE');
});
