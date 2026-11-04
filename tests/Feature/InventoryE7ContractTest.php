<?php

use App\Models\InventoryModule\InventoryBusiness;
use App\Models\PlatformModule\AnalyticsEvent;
use App\Services\InventoryModule\OutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    foreach (glob(database_path('migrations/platform/*.php')) as $file) {
        (require $file)->up();
    }
    config(['team4.business_id' => 'e4-contract-business', 'team4.integration.business_id' => 'e4-contract-business',
        'team4.integration.secrets.equipo7' => 'test-only-e7-to-inventory',
        'team4.integration.e7_inbox_enabled' => true,
        'platform.ingest.enabled' => true, 'platform.ingest.secrets.inventory' => 'test-only-inventory-to-e7',
        'team4.outbox.enabled' => true, 'team4.outbox.url' => 'http://e7.test/api/v1/integrations/ingest-event',
        'team4.outbox.secret' => 'test-only-inventory-to-e7']);
    InventoryBusiness::create(['business_id' => 'e4-contract-business', 'name' => 'Contrato E4', 'active' => true]);
    $migration = database_path('migrations/2026_10_30_000001_create_inventory_inbox_index.php');
    if (is_file($migration)) {
        (require $migration)->up();
    }
    Http::preventStrayRequests();
});

function inventoryContractEnvelope(): array
{
    return ['event_id' => 'evt_inv_fixed-1', 'event_type' => 'inventory.reservation.expired',
        'source_domain' => 'inventory', 'occurred_at' => '2026-10-29T18:30:00Z',
        'payload' => ['business_id' => 'e4-contract-business', 'reservation_id' => 'reservation-1', 'reason' => 'EXPIRED']];
}

function signedInventoryContract($test, string $path, array $body, string $secret = 'test-only-inventory-to-e7', int $offset = 0)
{
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $time = (string) (time() + $offset);

    return $test->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_TEAM_TIMESTAMP' => $time,
        'HTTP_X_TEAM_SIGNATURE' => hash_hmac('sha256', $time.'|POST|'.$path.'|'.hash('sha256', $json), $secret)], $json);
}

it('receives the E4 wire contract once without awarding points and rejects conflicting retries', function () {
    $body = inventoryContractEnvelope();
    $path = '/api/v1/integrations/ingest-event';
    signedInventoryContract($this, $path, $body)->assertStatus(202)->assertJson(['status' => 'accepted', 'event_id' => $body['event_id']]);
    signedInventoryContract($this, $path, $body)->assertOk()->assertJson(['status' => 'duplicate']);
    signedInventoryContract($this, $path, [...$body, 'payload' => [...$body['payload'], 'reason' => 'CHANGED']])->assertConflict();
    expect(AnalyticsEvent::count())->toBe(1)->and(AnalyticsEvent::first()->processed)->toBeFalse();
    expect(DB::connection('platform')->getCollection('points_ledger')->countDocuments())->toBe(0);
});

it('checks the source, timestamp, signature, event type and privacy at the E4 receiver', function () {
    $path = '/api/v1/integrations/ingest-event';
    $b = inventoryContractEnvelope();
    $this->postJson($path, $b)->assertUnauthorized();
    signedInventoryContract($this, $path, $b, 'wrong-secret')->assertUnauthorized();
    signedInventoryContract($this, $path, $b, 'test-only-inventory-to-e7', -600)->assertUnauthorized();
    signedInventoryContract($this, $path, [...$b, 'source_domain' => 'wallet'])->assertUnprocessable();
    signedInventoryContract($this, $path, [...$b, 'event_type' => 'wallet.credit'])->assertUnprocessable();
    signedInventoryContract($this, $path, [...$b, 'payload' => [...$b['payload'], 'context' => ['email' => 'private@example.test']]])->assertUnprocessable();
    config(['platform.ingest.enabled' => false]);
    signedInventoryContract($this, $path, $b)->assertStatus(503);
    expect(AnalyticsEvent::count())->toBe(0);
});

it('delivers a stable signed outbox envelope through the E7 receiver and keeps the original date', function () {
    $id = app(OutboxService::class)->publish('inventory.reservation.expired', ['reservation_id' => 'reservation-1']);
    $coll = DB::connection('inventory')->getCollection('integration_outbox');
    $created = $coll->findOne([])['created_at']->toDateTime()->format(DATE_ATOM);
    Http::fake(function ($request) {
        $response = $this->call('POST', '/api/v1/integrations/ingest-event', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_TEAM_TIMESTAMP' => $request->header('X-Team-Timestamp')[0] ?? '',
            'HTTP_X_TEAM_SIGNATURE' => $request->header('X-Team-Signature')[0] ?? '',
        ], $request->body());

        return Http::response($response->json(), $response->status());
    });
    $this->artisan('team4:publish-outbox')->assertSuccessful();
    $this->artisan('team4:publish-outbox')->assertSuccessful();
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r['event_id'] === 'evt_inv_'.$id && $r['occurred_at'] === $created && $r['payload']['business_id'] === 'e4-contract-business');
    expect($coll->findOne([])['status'])->toBe('SENT')->and(AnalyticsEvent::count())->toBe(1);
});

it('does not mark an unrelated successful HTTP response as an acknowledged delivery', function () {
    app(OutboxService::class)->publish('inventory.reservation.expired', ['reservation_id' => 'reservation-1']);
    Http::fake(['*' => Http::response(['status' => 'accepted', 'event_id' => 'another-event'], 202)]);
    $this->artisan('team4:publish-outbox')->assertFailed();
    expect(DB::connection('inventory')->getCollection('integration_outbox')->findOne([])['status'])->toBe('FAILED');
});

it('stores E7 notifications as receipts only and rejects changed content under the same ID', function () {
    $path = '/api/v1/integrations/eq7-events';
    $b = inventoryContractEnvelope();
    $b['source_domain'] = 'platform';
    $secret = 'test-only-e7-to-inventory';
    signedInventoryContract($this, $path, $b, $secret)->assertStatus(202)->assertJson(['status' => 'received']);
    signedInventoryContract($this, $path, $b, $secret)->assertOk()->assertJson(['status' => 'already_received']);
    signedInventoryContract($this, $path, [...$b, 'payload' => [...$b['payload'], 'reason' => 'CHANGED']], $secret)->assertConflict();
    $coll = DB::connection('inventory')->getCollection('integration_inbox');
    expect($coll->countDocuments())->toBe(1)->and($coll->findOne([])['status'])->toBe('RECEIVED');
    expect(DB::connection('inventory')->getCollection('stock_movements')->countDocuments())->toBe(0);
    config(['team4.integration.e7_inbox_enabled' => false]);
    signedInventoryContract($this, $path, $b, $secret)->assertStatus(503);
});

it('keeps notification receipts isolated by business and rejects unsigned or private E7 content', function () {
    $p = '/api/v1/integrations/eq7-events';
    $b = inventoryContractEnvelope();
    $this->postJson($p, $b)->assertUnauthorized();
    signedInventoryContract($this, $p, $b, 'wrong-secret')->assertUnauthorized();
    signedInventoryContract($this, $p, [...$b, 'payload' => [...$b['payload'], 'business_id' => 'another-business']], 'test-only-e7-to-inventory')->assertForbidden();
    signedInventoryContract($this, $p, [...$b, 'payload' => [...$b['payload'], 'context' => ['email' => 'private@example.test']]], 'test-only-e7-to-inventory')->assertUnprocessable();
    expect(DB::connection('inventory')->getCollection('integration_inbox')->countDocuments())->toBe(0);
});

it('rejects a reserved metadata field rather than masking a payload change', function () {
    $b = inventoryContractEnvelope();
    $b['payload']['source_event_id'] = 'another-original-id';
    signedInventoryContract($this, '/api/v1/integrations/ingest-event', $b)->assertUnprocessable();
    expect(AnalyticsEvent::count())->toBe(0);
});

it('dispatches the other declared inventory topics only when they already exist in its outbox', function () {
    $id = app(OutboxService::class)->publish('inventory.stock_updated', ['product_reference' => 'SKU-1']);
    Http::fake(['*' => Http::response(['status' => 'duplicate', 'event_id' => 'evt_inv_'.$id], 200)]);
    $this->artisan('team4:publish-outbox')->assertSuccessful();
    Http::assertSent(fn ($r) => $r['event_type'] === 'inventory.stock_updated');
    expect(DB::connection('inventory')->getCollection('integration_outbox')->findOne([])['status'])->toBe('SENT');
});
