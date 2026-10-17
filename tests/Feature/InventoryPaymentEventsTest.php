<?php

use App\Models\InventoryModule\InventoryBusiness;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;

beforeEach(function () {
    config(['team4.business_id' => 'payment-A', 'team4.integration.business_id' => 'payment-A',
        'team4.integration.payment_events_enabled' => true, 'team4.integration.secrets.equipo2' => 'test-payment-key']);
    InventoryBusiness::create(['business_id' => 'payment-A', 'name' => 'Pagos de prueba', 'active' => true]);
    (require database_path('migrations/2026_10_14_000000_create_inventory_domain_indexes.php'))->up();
    $migration = database_path('migrations/2026_10_17_000200_inventory_payment_events_v2.php');
    if (is_file($migration)) {
        (require $migration)->up();
    }
});

function paymentAdvanceBody(array $changes = []): array
{
    return array_replace(['event_id' => 'evt-payment-1', 'event_version' => 1, 'payment_intent_id' => 'pi-advance',
        'business_id' => 'payment-A', 'order_id' => 'order-advance', 'status' => 'PAID', 'amount' => 50,
        'currency' => 'MXN', 'occurred_at' => '2026-10-17T00:00:00Z'], $changes);
}

function paymentAdvanceSend($test, array $body)
{
    $path = '/api/equipo4/webhooks/payment-status';
    $timestamp = (string) time();

    return $test->postJson($path, $body, ['X-Team-Timestamp' => $timestamp,
        'X-Team-Signature' => hash_hmac('sha256', $timestamp.'|POST|'.$path.'|'.hash('sha256', json_encode($body)), 'test-payment-key')]);
}

function paymentAdvanceInventory(string $variant = 'variant-B'): array
{
    $db = DB::connection('inventory');
    $product = new ObjectId;
    $location = new ObjectId;
    $db->getCollection('products')->insertOne(['_id' => $product, 'business_id' => 'payment-A']);
    $db->getCollection('locations')->insertOne(['_id' => $location, 'business_id' => 'payment-A']);
    foreach (['variant-A', 'variant-B'] as $v) {
        $db->getCollection('inventories')->insertOne(['business_id' => 'payment-A', 'product_id' => (string) $product,
            'location_id' => (string) $location, 'variant_id' => $v, 'on_hand' => 10, 'available' => 10, 'reserved' => 0]);
    }

    return ['product_id' => (string) $product, 'location_id' => (string) $location, 'variant_id' => $variant, 'quantity' => 2];
}

it('requires the versioned financial envelope and refund identity', function () {
    $body = paymentAdvanceBody();
    unset($body['event_id']);
    paymentAdvanceSend($this, $body)->assertUnprocessable();
    paymentAdvanceSend($this, paymentAdvanceBody(['status' => 'REFUNDED']))->assertUnprocessable();
    expect(DB::connection('inventory')->getCollection('payment_events')->countDocuments([]))->toBe(0);
});

it('deduplicates the whole event and rejects changes to order currency or lines', function () {
    $body = paymentAdvanceBody();
    $id = paymentAdvanceSend($this, $body)->assertAccepted()->json('payment_event_id');
    paymentAdvanceSend($this, $body)->assertAccepted()->assertJsonPath('idempotent', true)->assertJsonPath('payment_event_id', $id);
    foreach (['order_id' => 'other-order', 'currency' => 'USD', 'event_version' => 2] as $key => $value) {
        paymentAdvanceSend($this, array_replace($body, [$key => $value]))->assertConflict();
    }
    expect(DB::connection('inventory')->getCollection('payment_events')->countDocuments([]))->toBe(1);
});

it('rejects stale versions without persisting them or poisoning later retries', function () {
    paymentAdvanceSend($this, paymentAdvanceBody(['event_id' => 'newer', 'event_version' => 3]))->assertAccepted();
    paymentAdvanceSend($this, paymentAdvanceBody(['event_id' => 'stale', 'event_version' => 2]))->assertConflict();
    paymentAdvanceSend($this, paymentAdvanceBody(['event_id' => 'stale', 'event_version' => 4]))->assertAccepted();
    expect(DB::connection('inventory')->getCollection('payment_events')->countDocuments([]))->toBe(2);
});

it('persists a pending return and applies the exact variant once through the worker', function () {
    $line = paymentAdvanceInventory();
    $body = paymentAdvanceBody(['status' => 'REFUNDED', 'refund_id' => 'refund-1', 'items' => [$line]]);
    paymentAdvanceSend($this, $body)->assertAccepted();
    $db = DB::connection('inventory');
    expect($db->getCollection('payment_events')->findOne(['event_id' => $body['event_id']])['return_status'])->toBe('PENDING');
    expect((int) $db->getCollection('inventories')->findOne(['variant_id' => 'variant-B'])['on_hand'])->toBe(10);
    $this->artisan('inventario:procesar-devoluciones')->assertSuccessful();
    $this->artisan('inventario:procesar-devoluciones')->assertSuccessful();
    paymentAdvanceSend($this, $body)->assertAccepted()->assertJsonPath('idempotent', true);
    expect((int) $db->getCollection('inventories')->findOne(['variant_id' => 'variant-B'])['on_hand'])->toBe(12)
        ->and((int) $db->getCollection('inventories')->findOne(['variant_id' => 'variant-A'])['on_hand'])->toBe(10)
        ->and($db->getCollection('stock_movements')->countDocuments(['refund_reference' => 'refund-1']))->toBe(1)
        ->and($db->getCollection('payment_events')->findOne(['event_id' => $body['event_id']])['return_status'])->toBe('PROCESSED');
});

it('rolls back every return line on failure and can retry without creating inventory', function () {
    $line = paymentAdvanceInventory();
    $bad = array_replace($line, ['variant_id' => 'missing']);
    paymentAdvanceSend($this, paymentAdvanceBody(['status' => 'REFUNDED', 'refund_id' => 'refund-failure', 'items' => [$line, $bad]]))->assertAccepted();
    $this->artisan('inventario:procesar-devoluciones')->assertFailed();
    $db = DB::connection('inventory');
    expect((int) $db->getCollection('inventories')->findOne(['variant_id' => 'variant-B'])['on_hand'])->toBe(10)
        ->and($db->getCollection('inventories')->countDocuments(['variant_id' => 'missing']))->toBe(0)
        ->and($db->getCollection('stock_movements')->countDocuments([]))->toBe(0);
    $db->getCollection('inventories')->insertOne(array_replace($bad, ['business_id' => 'payment-A', 'on_hand' => 0, 'available' => 0, 'reserved' => 0]));
    $this->travel(6)->minutes();
    $this->artisan('inventario:procesar-devoluciones')->assertSuccessful();
    expect((int) $db->getCollection('inventories')->findOne(['variant_id' => 'variant-B'])['on_hand'])->toBe(12)
        ->and($db->getCollection('stock_movements')->countDocuments([]))->toBe(2);
});

it('does not apply the same refund through a new event identity', function () {
    $line = paymentAdvanceInventory();
    $body = paymentAdvanceBody(['status' => 'REFUNDED', 'refund_id' => 'refund-stable', 'items' => [$line]]);
    paymentAdvanceSend($this, $body)->assertAccepted();
    paymentAdvanceSend($this, array_replace($body, ['event_id' => 'another-event', 'event_version' => 2]))->assertConflict();
    expect(DB::connection('inventory')->getCollection('payment_events')->countDocuments([]))->toBe(1);
});

it('keeps the payment receiver and return worker disabled without activation', function () {
    config(['team4.integration.payment_events_enabled' => false]);
    paymentAdvanceSend($this, paymentAdvanceBody())->assertStatus(503);
    $this->artisan('inventario:procesar-devoluciones')->assertSuccessful();
    expect(DB::connection('inventory')->getCollection('payment_events')->countDocuments([]))->toBe(0);
});

it('rolls back stock when writing the return movement fails', function () {
    $line = paymentAdvanceInventory();
    paymentAdvanceSend($this, paymentAdvanceBody(['status' => 'REFUNDED', 'refund_id' => 'refund-db-failure', 'items' => [$line]]))->assertAccepted();
    $db = DB::connection('inventory');
    $db->getDatabase()->command(['collMod' => 'stock_movements', 'validator' => ['type' => ['$ne' => 'RETURN']]])->toArray();
    $this->artisan('inventario:procesar-devoluciones')->assertFailed();
    expect((int) $db->getCollection('inventories')->findOne(['variant_id' => 'variant-B'])['on_hand'])->toBe(10)
        ->and($db->getCollection('stock_movements')->countDocuments([]))->toBe(0)
        ->and($db->getCollection('payment_events')->findOne([])['return_status'])->toBe('FAILED');
    $db->getDatabase()->command(['collMod' => 'stock_movements', 'validator' => (object) []])->toArray();
    $this->travel(6)->minutes();
    $this->artisan('inventario:procesar-devoluciones')->assertSuccessful();
    expect((int) $db->getCollection('inventories')->findOne(['variant_id' => 'variant-B'])['on_hand'])->toBe(12);
});

it('does not accept unsigned callbacks or a different business scope', function () {
    $this->postJson('/api/equipo4/webhooks/payment-status', paymentAdvanceBody())->assertUnauthorized();
    paymentAdvanceSend($this, paymentAdvanceBody(['business_id' => 'other-business']))->assertForbidden();
    expect(DB::connection('inventory')->getCollection('payment_events')->countDocuments([]))->toBe(0);
});

it('rejects another event at the same version and changes to the payment context', function () {
    paymentAdvanceSend($this, paymentAdvanceBody())->assertAccepted();
    paymentAdvanceSend($this, paymentAdvanceBody(['event_id' => 'same-version']))->assertConflict();
    paymentAdvanceSend($this, paymentAdvanceBody(['event_id' => 'changed-order', 'event_version' => 2, 'order_id' => 'another-order']))->assertConflict();
    expect(DB::connection('inventory')->getCollection('payment_events')->countDocuments([]))->toBe(1);
});
