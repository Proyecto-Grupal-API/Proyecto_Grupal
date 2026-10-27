<?php

use App\Domains\Financial\Models\Bonus;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\PurchasePayment;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\PurchasePaymentService;
use App\Domains\Financial\Services\PurchaseRefundService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

beforeEach(function () {
    config(['database.connections.sqlsrv.database' => 'campus_digital_financial_testing']);
    DB::purge('sqlsrv');
    if (! app()->environment('testing') || config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing'
        || config('database.connections.mongodb.database') !== 'campus_virtual_testing') {
        throw new RuntimeException('Solo bases de pruebas.');
    }
    DB::connection('sqlsrv')->beginTransaction();
    Http::preventStrayRequests();
    config(['financial_team4.enabled' => false]);
});
afterEach(function () {
    while (DB::connection('sqlsrv')->transactionLevel() > 0) {
        DB::connection('sqlsrv')->rollBack();
    }
});
function pmeFixture(int $bonusCents = 1000): array
{
    $owner = 'fixture-pme-'.Str::uuid();
    $wallet = Wallet::create(['public_id' => (string) Str::uuid(), 'owner_type' => 'USER', 'owner_id' => $owner, 'type' => 'WALLET_USUARIO',
        'currency' => 'MXN', 'status' => 'ACTIVA', 'available_balance_cents' => 10000, 'held_balance_cents' => 0]);
    $bonus = Bonus::create(['public_id' => (string) Str::uuid(), 'beneficiary_type' => 'USER', 'beneficiary_id' => $owner,
        'issuer_type' => 'USER', 'issuer_id' => 'fixture-issuer', 'type' => 'BECA', 'original_amount_cents' => $bonusCents,
        'remaining_amount_cents' => $bonusCents, 'currency' => 'MXN', 'status' => 'ACTIVO', 'valid_from' => now()->subDay(),
        'expires_at' => now()->addDay(), 'combinable' => true, 'allows_partial_use' => true]);

    return [$wallet, $bonus];
}
function pmeContext(): array
{
    return ['order_id' => 'ORDER-'.Str::uuid(), 'business_id' => 'BUS-PME', 'items' => [
        ['product_id' => 'P-1', 'variant_id' => null, 'location_id' => 'L-1', 'quantity' => 1],
    ]];
}
function pmeBuy(Wallet $wallet, Bonus $bonus, array $context, string $key, int $amount = 5000): PurchasePayment
{
    app(PurchasePaymentService::class)->pay($wallet, $amount, $bonus->public_id, $key, $context['business_id'], null, $context);

    return PurchasePayment::where('idempotency_key', $key)->firstOrFail();
}
function pmeBody(PurchasePayment $payment, int $version = 1): array
{
    $body = DB::connection('sqlsrv')->table('financial_team4_outbox')->where('event_version', $version)
        ->where('stream_id', DB::connection('sqlsrv')->table('financial_team4_streams')->where('payment_intent_id', strtolower($payment->public_id))->value('id'))->value('body');

    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
}
test('marketplace bridge records real mixed payment once and preserves the original order', function () {
    [$w,$b] = pmeFixture();
    $c = pmeContext();
    $key = 'pme-'.Str::uuid();
    $p = pmeBuy($w, $b, $c, $key);
    expect($w->fresh()->available_balance_cents)->toBe(6000)->and($b->fresh()->remaining_amount_cents)->toBe(0);
    $event = pmeBody($p);
    expect($event['status'])->toBe('PAID')->and($event['order_id'])->toBe($c['order_id'])
        ->and($event['payment_intent_id'])->toBe(strtolower($p->public_id))->and((float) $event['amount'])->toBe(50.0);
    pmeBuy($w, $b, $c, $key);
    expect(DB::connection('sqlsrv')->table('purchase_marketplace_links')->where('purchase_payment_id', $p->public_id)->count())->toBe(1)
        ->and($w->fresh()->available_balance_cents)->toBe(6000)->and(pmeBody($p)['event_id'])->toBe($event['event_id']);
    Http::assertNothingSent();
});
test('marketplace bridge invalid context rolls back wallet bonus purchase and ledger', function () {
    [$w,$b] = pmeFixture();
    $c = pmeContext();
    $c['items'][0]['quantity'] = 0;
    $key = 'pme-'.Str::uuid();
    $entries = LedgerEntry::where('wallet_id', $w->public_id)->count();
    expect(fn () => pmeBuy($w, $b, $c, $key))->toThrow(InvalidArgumentException::class);
    expect($w->fresh()->available_balance_cents)->toBe(10000)->and($b->fresh()->remaining_amount_cents)->toBe(1000)
        ->and(PurchasePayment::where('idempotency_key', $key)->exists())->toBeFalse()
        ->and(LedgerEntry::where('wallet_id', $w->public_id)->count())->toBe($entries);
});
test('marketplace replay rejects changed and omitted context and does not attach historical purchases', function () {
    [$w,$b] = pmeFixture();
    $c = pmeContext();
    $key = 'pme-'.Str::uuid();
    pmeBuy($w, $b, $c, $key);
    expect(fn () => pmeBuy($w, $b, [...$c, 'order_id' => 'OTHER'], $key))->toThrow(ConflictHttpException::class);
    expect(fn () => app(PurchasePaymentService::class)->pay($w, 5000, $b->public_id, $key, 'BUS-PME'))->toThrow(ConflictHttpException::class);
    [$w2,$b2] = pmeFixture();
    $old = 'pme-old-'.Str::uuid();
    app(PurchasePaymentService::class)->pay($w2, 5000, $b2->public_id, $old, 'BUS-PME');
    expect(fn () => pmeBuy($w2, $b2, pmeContext(), $old))->toThrow(ConflictHttpException::class);
});
test('marketplace duplicate order cannot charge a second purchase even with a new key', function () {
    [$w,$b] = pmeFixture(2000);
    $c = pmeContext();
    pmeBuy($w, $b, $c, 'pme-'.Str::uuid(), 1000);
    $balance = $b->fresh()->remaining_amount_cents;
    expect(fn () => pmeBuy($w, $b, $c, 'pme-other-'.Str::uuid(), 1000))->toThrow(UniqueConstraintViolationException::class);
    expect($b->fresh()->remaining_amount_cents)->toBe($balance);
});
test('marketplace fully bonus funded purchase emits PAID without a wallet ledger transaction', function () {
    [$w,$b] = pmeFixture(5000);
    $p = pmeBuy($w, $b, pmeContext(), 'pme-'.Str::uuid());
    expect(LedgerEntry::where('wallet_id', $w->public_id)->count())->toBe(0)
        ->and($w->fresh()->available_balance_cents)->toBe(10000)->and(pmeBody($p)['status'])->toBe('PAID');
});
test('marketplace refund events follow completion only and use delta amount without physical return items', function () {
    [$w,$b] = pmeFixture();
    $p = pmeBuy($w, $b, pmeContext(), 'pme-'.Str::uuid());
    $service = app(PurchaseRefundService::class);
    $r = $service->request($p, 2000, [], 'pme-req-'.Str::uuid(), 'buyer');
    $service->approve($r, 'reviewer');
    $stream = DB::connection('sqlsrv')->table('financial_team4_streams')->where('payment_intent_id', strtolower($p->public_id))->value('id');
    expect(DB::connection('sqlsrv')->table('financial_team4_outbox')->where('stream_id', $stream)->count())->toBe(1);
    $key = 'pme-exec-'.Str::uuid();
    $service->complete($r, $key, 'executor');
    $partial = pmeBody($p, 2);
    expect($partial['status'])->toBe('PARTIALLY_REFUNDED')->and((float) $partial['amount'])->toBe(20.0)->and($partial['items'])->toBe([]);
    $service->complete($r->fresh(), $key, 'executor');
    expect(DB::connection('sqlsrv')->table('financial_team4_outbox')->where('stream_id', $stream)->count())->toBe(2);
    $r2 = $service->request($p, 2000, [['bonus_id' => $b->public_id, 'amount_cents' => 1000]], 'pme-req-'.Str::uuid(), 'buyer');
    $service->approve($r2, 'reviewer');
    $service->complete($r2, 'pme-exec-'.Str::uuid(), 'executor');
    expect(pmeBody($p, 3)['status'])->toBe('REFUNDED')->and((float) pmeBody($p, 3)['amount'])->toBe(30.0)
        ->and($w->fresh()->available_balance_cents)->toBe(10000)->and($b->fresh()->remaining_amount_cents)->toBe(1000);
    Http::assertNothingSent();
});
test('marketplace multiple bonuses share one purchase event', function () {
    [$w,$b] = pmeFixture(500);
    $b2 = $b->replicate();
    $b2->public_id = (string) Str::uuid();
    $b2->save();
    $c = pmeContext();
    $key = 'pme-multi-'.Str::uuid();
    app(PurchasePaymentService::class)->payWithMultipleBonuses($w, 1500, [$b->public_id, $b2->public_id], $key, 'BUS-PME', null, $c);
    $p = PurchasePayment::where('idempotency_key', $key)->firstOrFail();
    expect($w->fresh()->available_balance_cents)->toBe(9500)->and(pmeBody($p)['status'])->toBe('PAID');
});
test('marketplace failed refund event rolls back the financial completion', function () {
    [$w,$b] = pmeFixture();
    $p = pmeBuy($w, $b, pmeContext(), 'pme-'.Str::uuid());
    $service = app(PurchaseRefundService::class);
    $r = $service->request($p, 1000, [], 'pme-req-'.Str::uuid(), 'buyer');
    $service->approve($r, 'reviewer');
    DB::connection('sqlsrv')->table('financial_team4_streams')->where('payment_intent_id',strtolower($p->public_id))->update(['order_id' => 'CORRUPTED-FIXTURE']);
    expect(fn () => $service->complete($r,'pme-exec-'.Str::uuid(),'executor'))->toThrow(ConflictHttpException::class);
    expect($w->fresh()->available_balance_cents)->toBe(6000)->and($r->fresh()->status->value)->toBe('APROBADA');
});
