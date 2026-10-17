<?php

use App\Services\InventoryModule\OutboxService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use MongoDB\BSON\UTCDateTime;

beforeEach(function () {
    config(['team4.business_id' => 'outbox-business', 'team4.outbox.enabled' => true,
        'team4.outbox.url' => 'http://e7.test/events', 'team4.outbox.token' => 'test-only-e7']);
    Http::preventStrayRequests();
});

it('keeps E7 transport inactive until explicitly configured', function () {
    config(['team4.outbox.enabled' => false]);
    app(OutboxService::class)->publish('inventory.reservation.expired', ['reservation_id' => 'expired-1']);
    $this->artisan('team4:publish-outbox')->assertSuccessful();
    Http::assertNothingSent();
    expect(DB::connection('inventory')->getCollection('integration_outbox')->findOne([])['status'])->toBe('PENDING');
});

it('retries a failed delivery with the same event identity and does not resend a success', function () {
    $id = app(OutboxService::class)->publish('inventory.reservation.expired', ['reservation_id' => 'expired-1']);
    Http::fakeSequence()->push([], 503)->push([], 202);
    $this->artisan('team4:publish-outbox')->assertFailed();
    $this->artisan('team4:publish-outbox')->assertSuccessful();
    Http::assertSentCount(1);
    $this->travel(6)->minutes();
    $this->artisan('team4:publish-outbox')->assertSuccessful();
    $this->artisan('team4:publish-outbox')->assertSuccessful();
    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => $r->header('X-Event-Id')[0] === $id && $r['business_id'] === 'outbox-business' && $r['reservation_id'] === 'expired-1');
    $event = DB::connection('inventory')->getCollection('integration_outbox')->findOne([]);
    expect($event['status'])->toBe('SENT')->and((int) $event['attempts'])->toBe(2)->and($event['last_error'])->toBeNull();
});

it('keeps connection failures retryable and refuses legacy events with no business', function () {
    app(OutboxService::class)->publish('inventory.reservation.expired', ['reservation_id' => 'expired-2']);
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        throw new ConnectionException('Simulated test-only network failure');
    });
    $this->artisan('team4:publish-outbox')->assertFailed();
    $event = DB::connection('inventory')->getCollection('integration_outbox')->findOne([]);
    expect($event['status'])->toBe('FAILED')->and($event['next_attempt_at'])->not->toBeNull();
    DB::connection('inventory')->getCollection('integration_outbox')->insertOne(['topic' => 'inventory.reservation.expired', 'status' => 'PENDING', 'payload' => []]);
    $this->artisan('team4:publish-outbox')->assertFailed();
    expect($calls)->toBe(1);
});

it('does not take a current delivery claim and recovers an expired one', function () {
    app(OutboxService::class)->publish('inventory.reservation.expired', ['reservation_id' => 'leased']);
    $coll = DB::connection('inventory')->getCollection('integration_outbox');
    $coll->updateOne([], ['$set' => ['claim_token' => 'interrupted-claim', 'claim_expires_at' => new UTCDateTime(now()->addSeconds(30))]]);
    Http::fake(['*' => Http::response([], 202)]);
    $this->artisan('team4:publish-outbox')->assertSuccessful();
    Http::assertNothingSent();
    $this->travel(31)->seconds();
    $this->artisan('team4:publish-outbox')->assertSuccessful();
    Http::assertSentCount(1);
    expect($coll->findOne([])['status'])->toBe('SENT');
});
