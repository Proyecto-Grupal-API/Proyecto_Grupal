<?php

use App\Models\InventoryModule\CostHistory;
use App\Models\InventoryModule\InventoryBusiness;
use App\Models\InventoryModule\Product;
use App\Services\InventoryModule\BusinessContext;
use App\Services\InventoryModule\CostService;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;

beforeEach(function () {
    config(['team4.business_id' => 'advance-A', 'team4.integration.business_id' => 'advance-A',
        'team4.integration.secrets' => ['equipo3' => 'advance-e3', 'equipo2' => 'advance-e2', 'equipo7' => 'advance-e7']]);
    InventoryBusiness::create(['business_id' => 'advance-A', 'name' => 'Avance', 'active' => true]);
    (require database_path('migrations/2026_10_14_000000_create_inventory_domain_indexes.php'))->up();
    $this->artisan('team4:init-indexes')->assertSuccessful();
    app(BusinessContext::class)->select('advance-A');
});

function inventoryAdvanceRequest($test, string $method, string $path, array $body = [], string $consumer = 'equipo3')
{
    $json = $method === 'GET' ? '' : json_encode($body);
    $timestamp = (string) time();

    return $test->call($method, $path, [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_TEAM_TIMESTAMP' => $timestamp,
        'HTTP_X_TEAM_SIGNATURE' => hash_hmac('sha256', $timestamp.'|'.$method.'|'.$path.'|'.hash('sha256', $json), config('team4.integration.secrets.'.$consumer)),
    ], $json);
}

it('returns the cost it actually stores and updates the pricing reference', function () {
    $product = Product::create(['business_id' => 'advance-A', 'sku' => 'COST-1', 'name' => 'Costo', 'active' => true]);
    $product = Product::where('sku', 'COST-1')->firstOrFail();
    $cost = app(CostService::class)->register(['product_id' => (string) $product->getKey(), 'cost' => 25, 'suggested_price' => 50]);
    expect($cost)->toBeInstanceOf(CostHistory::class)->and(CostHistory::count())->toBe(1);
    $reference = DB::connection('inventory')->getCollection('pricing_references')->findOne(['product_id' => (string) $product->getKey()]);
    expect((float) $reference['last_cost'])->toBe(25.0)->and((float) $reference['suggested_margin'])->toBe(50.0);
});

it('serializes deadlines for each consumer and rejects reservations from the other origin', function () {
    $deadline = now()->startOfSecond()->addMinutes(15);
    $reservations = DB::connection('inventory')->getCollection('team3_api_reservations');
    $base = ['business_id' => 'advance-A', 'order_id' => 'ADVANCE-ORDER',
        'status' => 'RESERVED', 'items' => [], 'expires_at' => new UTCDateTime($deadline)];
    $reservations->insertOne($base + ['reservation_id' => 'ADVANCE-E3']);
    $reservations->insertOne($base + ['reservation_id' => 'ADVANCE-E7', 'origin_system' => 'E7']);
    inventoryAdvanceRequest($this, 'GET', '/api/equipo4/integration/reservations/ADVANCE-E3')
        ->assertOk()->assertJsonPath('expires_at', $deadline->format(DATE_ATOM));
    inventoryAdvanceRequest($this, 'GET', '/api/v1/inventory/reservations/ADVANCE-E7', [], 'equipo7')
        ->assertOk()->assertJsonPath('expires_at', $deadline->format(DATE_ATOM));
    inventoryAdvanceRequest($this, 'GET', '/api/equipo4/integration/reservations/ADVANCE-E7')->assertNotFound();
    inventoryAdvanceRequest($this, 'GET', '/api/v1/inventory/reservations/ADVANCE-E3', [], 'equipo7')->assertNotFound();
});

it('uses the highest accepted payment version only within the reservation business and order', function () {
    $db = DB::connection('inventory');
    $db->getCollection('team3_api_reservations')->insertOne([
        'business_id' => 'advance-A', 'reservation_id' => 'ADVANCE-REFUND', 'order_id' => 'order-version',
        'payment_intent_id' => 'pi-version', 'status' => 'RESERVED', 'items' => [],
    ]);
    foreach ([['advance-A', 2, 'REFUNDED', -5], ['advance-A', 1, 'PAID', 20], ['foreign-business', 99, 'PAID', 30]] as [$business, $version, $status, $minutes]) {
        $db->getCollection('payment_events')->insertOne(['business_id' => $business, 'event_version' => $version,
            'order_id' => 'order-version', 'payment_intent_id' => 'pi-version', 'status' => $status,
            'received_at' => new UTCDateTime(now()->addMinutes($minutes))]);
    }
    inventoryAdvanceRequest($this, 'GET', '/api/equipo4/integration/reservations/ADVANCE-REFUND')
        ->assertOk()->assertJsonPath('payment.status', 'REFUNDED');
    inventoryAdvanceRequest($this, 'POST', '/api/equipo4/integration/reservations/ADVANCE-REFUND/confirm', [
        'order_id' => 'order-version', 'payment_intent_id' => 'pi-version', 'idempotency_key' => 'confirm-version-123',
    ])->assertConflict()->assertJsonPath('payment_status', 'REFUNDED');
});

it('captures stock count lines only from the selected count and retains differences from earlier captures', function () {
    $this->withoutMiddleware();
    $db = DB::connection('inventory');
    $counts = $db->getCollection('stock_counts');
    $items = $db->getCollection('stock_count_items');
    $count = $counts->insertOne(['business_id' => 'advance-A', 'status' => 'DRAFT', 'differences_count' => 0])->getInsertedId();
    $otherCount = $counts->insertOne(['business_id' => 'advance-A', 'status' => 'DRAFT'])->getInsertedId();
    $base = ['business_id' => 'advance-A', 'expected_qty' => 5, 'counted_qty' => 0, 'difference' => 0];
    $first = $items->insertOne($base + ['stock_count_id' => (string) $count])->getInsertedId();
    $second = $items->insertOne($base + ['stock_count_id' => (string) $count])->getInsertedId();
    $foreign = $items->insertOne($base + ['stock_count_id' => (string) $otherCount])->getInsertedId();
    $path = route('equipo4.conteos.capture', ['id' => (string) $count], false);

    $this->getJson(route('equipo4.conteos.show', ['id' => (string) $count], false))
        ->assertOk()->assertJsonPath('_id', (string) $count)->assertJsonCount(2, 'items')
        ->assertJsonFragment(['_id' => (string) $first]);
    $this->postJson($path, ['items' => [
        ['item_id' => (string) $first, 'counted_qty' => 4],
        ['item_id' => (string) $foreign, 'counted_qty' => 1],
    ]])->assertUnprocessable();
    expect($items->findOne(['_id' => $first])['counted_qty'])->toBe(0)
        ->and($items->findOne(['_id' => $foreign])['counted_qty'])->toBe(0);

    foreach ([$first, $second] as $item) {
        $this->postJson($path, ['items' => [['item_id' => (string) $item, 'counted_qty' => 4]]])->assertRedirect();
    }
    expect($counts->findOne(['_id' => $count])['differences_count'])->toBe(2);
});
