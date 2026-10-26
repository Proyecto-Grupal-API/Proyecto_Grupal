<?php

use App\Domains\Financial\Integrations\Team4\{PaymentEventOutbox, Team4WebhookDelivery, Team4OutboxAdministration};
use App\Domains\Financial\Models\{Wallet, PurchasePayment, LedgerEntry};
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\{ConflictHttpException, NotFoundHttpException};

beforeEach(function () {
    if (!app()->environment('testing')
        || config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing'
        || config('database.connections.mongodb.database') !== 'campus_virtual_testing') {
        throw new RuntimeException('Solo bases de pruebas.');
    }
    DB::connection('sqlsrv')->beginTransaction();
    config(['financial_team4.enabled' => false, 'financial_team4.url' => 'http://inventory.test/api/equipo4/webhooks/payment-status',
        'financial_team4.secret' => 'fixture-operations-no-real-secret']);
    Http::preventStrayRequests();
});

afterEach(function () {
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
});

function t4adminFixture(?array $payload = null): object
{
    $payload ??= ['payment_intent_id' => 'PI-'.Str::uuid(), 'order_id' => 'ORDER-'.Str::uuid(),
        'business_id' => 'DEV-CHECKOUT-LOCAL', 'status' => 'PAID', 'amount_cents' => 100,
        'occurred_at' => '2026-10-26T15:47:40Z'];
    $id = app(PaymentEventOutbox::class)->record('fixture-operations-'.Str::uuid(), $payload);
    return DB::connection('sqlsrv')->table('financial_team4_outbox')->where('event_id', $id)->first();
}

function t4adminQuarantine(object $event, ?string $actionId = null): array
{
    return app(Team4OutboxAdministration::class)->quarantine($event->event_id, $event->body_hash,
        $actionId ?? (string) Str::uuid(), 'test-operator', 'Evento de desarrollo, no enviar al receptor real.');
}

test('team4 operations list and detail are read only paginated filtered and preserve exact body', function () {
    $a = t4adminFixture(); $b = t4adminFixture();
    $service = app(Team4OutboxAdministration::class);
    $payment = DB::connection('sqlsrv')->table('financial_team4_streams')->where('id', $a->stream_id)->value('payment_intent_id');
    $first = $service->listing(null, null, 1);
    expect($first['events'])->toHaveCount(1)->and($first['next_before_id'])->not->toBeNull();
    $next = $service->listing(null, null, 1, $first['next_before_id']);
    expect($next['events'][0]['event_id'])->not->toBe($first['events'][0]['event_id']);
    expect($service->listing('PENDING', $payment)['events'])->toHaveCount(1)
        ->and($service->listing('DELIVERED', $payment)['events'])->toBe([]);
    $shown = $service->show(strtoupper($a->event_id));
    expect($shown['stored_body'])->toBe($a->body)->and($shown['integrity_ok'])->toBeTrue()->and($shown['actions'])->toBe([])
        ->and(DB::connection('sqlsrv')->table('financial_team4_outbox')->where('id', $a->id)->first()->state)->toBe('PENDING');
    Http::assertNothingSent();
});

test('team4 quarantine atomically retains audit body money and attempts without pretending delivery', function () {
    $event = t4adminFixture();
    $wallet = Wallet::create(['public_id' => (string) Str::uuid(), 'owner_type' => 'USER', 'owner_id' => 'operations-'.Str::uuid(),
        'type' => 'WALLET_USUARIO', 'currency' => 'MXN', 'status' => 'ACTIVA', 'available_balance_cents' => 11900, 'held_balance_cents' => 0]);
    $ledger = LedgerEntry::count(); $purchases = PurchasePayment::count();
    $result = t4adminQuarantine($event);
    $row = DB::connection('sqlsrv')->table('financial_team4_outbox')->where('id', $event->id)->first();
    $audit = DB::connection('sqlsrv')->table('financial_team4_operator_actions')->where('event_id', $event->event_id)->first();
    expect($result['idempotent'])->toBeFalse()->and($row->state)->toBe('REVIEW')->and($row->last_code)->toBe('OPERATOR_QUARANTINE')
        ->and($row->body)->toBe($event->body)->and($row->body_hash)->toBe($event->body_hash)
        ->and((int) $row->attempts)->toBe(0)->and($row->delivered_at)->toBeNull()
        ->and($row->lease_token)->toBeNull()->and($audit->from_state)->toBe('PENDING')->and($audit->to_state)->toBe('REVIEW')
        ->and($audit->operator_label)->toBe('test-operator')->and($audit->body_hash)->toBe($event->body_hash)
        ->and($wallet->fresh()->available_balance_cents)->toBe(11900)->and(LedgerEntry::count())->toBe($ledger)
        ->and(PurchasePayment::count())->toBe($purchases);
    Http::assertNothingSent();
});

test('team4 quarantine retries same action once and rejects altered operator reason or event', function () {
    $event = t4adminFixture(); $other = t4adminFixture(); $actionId = (string) Str::uuid();
    t4adminQuarantine($event, $actionId);
    expect(t4adminQuarantine($event, strtoupper($actionId))['idempotent'])->toBeTrue();
    expect(DB::connection('sqlsrv')->table('financial_team4_operator_actions')->where('event_id', $event->event_id)->count())->toBe(1);
    $service = app(Team4OutboxAdministration::class);
    expect(fn () => $service->quarantine($event->event_id, $event->body_hash, $actionId, 'another-operator', 'Otro motivo de desarrollo.'))
        ->toThrow(ConflictHttpException::class);
    expect(fn () => t4adminQuarantine($other, $actionId))->toThrow(ConflictHttpException::class);
    expect(DB::connection('sqlsrv')->table('financial_team4_outbox')->where('id', $other->id)->value('state'))->toBe('PENDING');
});

test('team4 quarantine blocks the stream while other payments can still be sent', function () {
    $payload = ['payment_intent_id' => 'PI-'.Str::uuid(), 'order_id' => 'ORDER-'.Str::uuid(),
        'business_id' => 'DEV-CHECKOUT-LOCAL', 'status' => 'PAID', 'amount_cents' => 100, 'occurred_at' => '2026-10-26T15:47:40Z'];
    $first = t4adminFixture($payload);
    $later = t4adminFixture([...$payload, 'status' => 'REFUNDED', 'refund_id' => 'RF-OPS', 'items' => []]);
    $other = t4adminFixture(); t4adminQuarantine($first);
    foreach ([$first, $later, $other] as $row) DB::connection('sqlsrv')->table('financial_team4_outbox')->where('id', $row->id)->update(['available_at' => now()->subSecond()]);
    config(['financial_team4.enabled' => true]);
    Http::fake(['inventory.test/*' => Http::response(['received' => true, 'payment_event_id' => 'PE-OPS'], 202)]);
    expect(app(Team4WebhookDelivery::class)->run())->toBe(1);
    expect(DB::connection('sqlsrv')->table('financial_team4_outbox')->where('id', $first->id)->value('state'))->toBe('REVIEW')
        ->and((int) DB::connection('sqlsrv')->table('financial_team4_outbox')->where('id', $later->id)->value('attempts'))->toBe(0)
        ->and(DB::connection('sqlsrv')->table('financial_team4_outbox')->where('id', $other->id)->value('state'))->toBe('DELIVERED');
    Http::assertSentCount(1);
});

test('team4 quarantine rejects events claimed delivered retried or already reviewed', function () {
    foreach (['SENDING', 'DELIVERED', 'RETRY', 'REVIEW', 'EXHAUSTED'] as $state) {
        $event = t4adminFixture();
        DB::connection('sqlsrv')->table('financial_team4_outbox')->where('id', $event->id)->update(['state' => $state]);
        expect(fn () => t4adminQuarantine($event))->toThrow(ConflictHttpException::class);
        expect(DB::connection('sqlsrv')->table('financial_team4_operator_actions')->where('event_id', $event->event_id)->count())->toBe(0);
    }
    Http::assertNothingSent();
});

test('team4 quarantine rejects prior attempt counters records and leases even on pending rows', function () {
    foreach ([['attempts' => 1], ['lease_token' => (string) Str::uuid()], ['lease_until' => now()->subMinute()], ['delivered_at' => now()]] as $changes) {
        $event = t4adminFixture();
        DB::connection('sqlsrv')->table('financial_team4_outbox')->where('id', $event->id)->update($changes);
        expect(fn () => t4adminQuarantine($event))->toThrow(ConflictHttpException::class);
    }
    $event = t4adminFixture();
    DB::connection('sqlsrv')->table('financial_team4_attempts')->insert(['event_id' => $event->event_id, 'attempt_no' => 1,
        'lease_token' => (string) Str::uuid(), 'started_at' => now()]);
    expect(fn () => t4adminQuarantine($event))->toThrow(ConflictHttpException::class);
});

test('team4 quarantine rejects stale hash or corrupted body without mutating evidence', function () {
    $event = t4adminFixture();
    $service = app(Team4OutboxAdministration::class);
    expect(fn () => $service->quarantine($event->event_id, str_repeat('0', 64), (string) Str::uuid(), 'operator', 'Motivo valido de prueba.'))
        ->toThrow(ConflictHttpException::class);
    DB::connection('sqlsrv')->table('financial_team4_outbox')->where('id', $event->id)->update(['body' => '{}']);
    expect($service->show($event->event_id)['integrity_ok'])->toBeFalse();
    expect(fn () => t4adminQuarantine($event))->toThrow(ConflictHttpException::class);
    expect(DB::connection('sqlsrv')->table('financial_team4_outbox')->where('id', $event->id)->value('state'))->toBe('PENDING');
});

test('team4 quarantine audit persistence failure rolls back all changes and allows retry', function () {
    $event = t4adminFixture(); $actionId = (string) Str::uuid();
    // Simulates a DB failure after the transactional audit insert, before the state update.
    $connection = DB::connection('sqlsrv');
    $fail = true;
    $connection->beforeExecuting(function ($query) use (&$fail) {
        if ($fail && str_starts_with(strtolower(ltrim($query)), 'update [financial_team4_outbox]')) {
            throw new RuntimeException('fixture persistence failure');
        }
    });
    try {
        expect(fn () => t4adminQuarantine($event, $actionId))->toThrow(RuntimeException::class);
        expect($connection->table('financial_team4_operator_actions')->where('event_id', $event->event_id)->count())->toBe(0)
            ->and($connection->table('financial_team4_outbox')->where('id', $event->id)->value('state'))->toBe('PENDING');
    } finally { $fail = false; }
    expect(t4adminQuarantine($event, $actionId)['idempotent'])->toBeFalse();
});

test('team4 operational command previews without write and requires explicit confirmation parameters', function () {
    $event = t4adminFixture();
    $this->artisan('financial:team4-events', ['action' => 'quarantine', 'event' => $event->event_id])
        ->expectsOutputToContain('preview_only')->assertSuccessful();
    expect(DB::connection('sqlsrv')->table('financial_team4_outbox')->where('id', $event->id)->value('state'))->toBe('PENDING');
    $this->artisan('financial:team4-events', ['action' => 'quarantine', 'event' => $event->event_id, '--confirm' => true])->assertFailed();
    Http::assertNothingSent();
});

test('team4 operational command applies exact event and returns audit without requiring emitter', function () {
    $event = t4adminFixture(); $actionId = (string) Str::uuid();
    $options = ['action' => 'quarantine', 'event' => $event->event_id, '--body-hash' => $event->body_hash,
        '--action-id' => $actionId, '--operator' => 'test-operator', '--reason' => 'Apartar evidencia de prueba local.', '--confirm' => true];
    $this->artisan('financial:team4-events', $options)->expectsOutputToContain('REVIEW')->assertSuccessful();
    $this->artisan('financial:team4-events', $options)->expectsOutputToContain('"idempotent": true')->assertSuccessful();
    $this->artisan('financial:team4-events', ['action' => 'show', 'event' => $event->event_id])->expectsOutputToContain('OPERATOR_QUARANTINE')->assertSuccessful();
    expect(DB::connection('sqlsrv')->table('financial_team4_operator_actions')->where('event_id', $event->event_id)->count())->toBe(1);
    Http::assertNothingSent();
});

test('team4 operations validate inputs cursor and action before changing any event', function () {
    $service = app(Team4OutboxAdministration::class); $event = t4adminFixture();
    expect(fn () => $service->listing('PAID'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->listing(null, null, 101))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->show('../invalid'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->show((string) Str::uuid()))->toThrow(NotFoundHttpException::class);
    expect(fn () => $service->quarantine($event->event_id, $event->body_hash, (string) Str::uuid(), 'op', 'short'))->toThrow(InvalidArgumentException::class);
    $this->artisan('financial:team4-events', ['--limit' => '1.5'])->assertFailed();
    $this->artisan('financial:team4-events', ['action' => 'deliver'])->assertFailed();
    Http::assertNothingSent();
});

test('team4 quarantine refuses production but read only inspection stays available', function () {
    $event = t4adminFixture(); $original = app()['env']; app()->instance('env', 'production');
    try {
        expect(fn () => t4adminQuarantine($event))->toThrow(ConflictHttpException::class);
        expect(app(Team4OutboxAdministration::class)->show($event->event_id)['state'])->toBe('PENDING');
    } finally { app()->instance('env', $original); }
    Http::assertNothingSent();
});

test('team4 claimed worker wins and operational quarantine cannot interrupt or fake its acknowledgement', function () {
    $event = t4adminFixture();
    DB::connection('sqlsrv')->table('financial_team4_outbox')->where('id', $event->id)->update(['available_at' => now()->subSecond()]);
    config(['financial_team4.enabled' => true]);
    Http::fake(function () use ($event) {
        // Reentrant operator arrives after the sender has claimed its lease, before HTTP acknowledgement.
        expect(fn () => t4adminQuarantine($event))->toThrow(ConflictHttpException::class);
        return Http::response(['received' => true, 'payment_event_id' => 'PE-LEASE-WINS'], 202);
    });
    expect(app(Team4WebhookDelivery::class)->run())->toBe(1);
    expect(DB::connection('sqlsrv')->table('financial_team4_outbox')->where('id', $event->id)->value('state'))->toBe('DELIVERED')
        ->and(DB::connection('sqlsrv')->table('financial_team4_operator_actions')->where('event_id', $event->event_id)->count())->toBe(0);
    Http::assertSentCount(1);
});
