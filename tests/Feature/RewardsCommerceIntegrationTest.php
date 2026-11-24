<?php

use App\Http\Middleware\EnsureRequiredTwoFactorAuthentication;
use App\Http\Middleware\EnsureSessionIsActive;
use App\Http\Middleware\TrackDeviceSession;
use App\Models\Marketplace\Order;
use App\Models\PlatformModule\AnalyticsEvent;
use App\Models\PlatformModule\AuditLog;
use App\Models\PlatformModule\FraudFlag;
use App\Models\PlatformModule\IntegrationLog;
use App\Models\PlatformModule\PointsLedger;
use App\Models\PlatformModule\WebhookEvent;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Marketplace\MarketplaceRewardsEvents;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;

/*
 * Contratos del diseño (§15): «Acumular puntos por venta» (E3 → E7) y «Revertir puntos por devolución».
 * Tienda avisa con un evento firmado; Recompensas decide el movimiento, lo registra en su bandeja de eventos y
 * permite reintentar lo que quedó en falla (7.10 Integración y observabilidad).
 */
beforeEach(function () {
    (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
    (require database_path('migrations/2026_11_15_000100_create_marketplace_rewards_outbox_index.php'))->up();
    $this->seed(RoleSeeder::class);
    foreach (glob(database_path('migrations/platform/*.php')) as $file) {
        (require $file)->up();
    }
    config(['platform.ingest.enabled' => true, 'platform.ingest.secrets.marketplace' => 'test-marketplace-secret-0001',
        'marketplace.rewards.enabled' => true, 'marketplace.rewards.url' => 'http://app']);
    $this->withoutMiddleware([TrackDeviceSession::class, EnsureSessionIsActive::class, EnsureRequiredTwoFactorAuthentication::class]);
    $make = fn () => User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]);
    $this->program = $make();
    $this->program->assignRole(Role::ADMIN, 'service', 'recompensas');
    $this->auditor = $make();
    $this->auditor->assignRole(Role::ADMIN, 'service', 'auditoria');
    $this->buyer = $make();
    StudentProfile::create(['user_id' => (string) $this->buyer->getKey(), 'enrollment_number' => 'COM-1', 'academic_status' => 'active']);
});

function commerceEvent(User $buyer, string $name = 'marketplace.order.confirmed.v1', string $folio = 'CD3-0001', int $cents = 60000, string $business = 'BUS-COM'): array
{
    return ['event_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'campus:marketplace:'.$name.':'.$folio), 'event_name' => $name,
        'source_domain' => 'marketplace', 'aggregate_id' => $folio, 'occurred_at' => now()->toIso8601String(),
        'payload' => ['order_folio' => $folio, 'business_id' => $business, 'buyer_id' => (string) $buyer->getKey(), 'total_cents' => $cents, 'currency' => 'MXN']];
}

function signedCommerce($test, array $body, string $secret = 'test-marketplace-secret-0001')
{
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $time = (string) time();
    $path = '/api/v1/plataforma/eventos';

    return $test->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_PLATFORM_DOMAIN' => 'marketplace',
        'HTTP_X_PLATFORM_TIMESTAMP' => $time, 'HTTP_X_PLATFORM_SIGNATURE' => hash_hmac('sha256', $time.'|POST|'.$path.'|'.hash('sha256', $json), $secret)], $json);
}

function commerceProgram($test, bool $withRule = true): void
{
    $test->actingAs($test->program);
    $test->postJson('/api/v1/recompensas/businesses', ['business_id' => 'BUS-COM', 'business_name' => 'Souvenirs', 'status' => 'active'])->assertCreated();
    if ($withRule) {
        // Ejemplo del diseño (§18): 1 punto por cada $2, máximo 500 por operación.
        $test->postJson('/api/v1/recompensas/rules', ['business_id' => 'BUS-COM', 'name' => 'Souvenirs 1x2', 'type' => 'amount_spent', 'points_per_unit' => 1,
            'unit_amount' => 2, 'max_points_per_operation' => 500, 'status' => 'active', 'active_from' => now()->subDay()->toDateString()])->assertCreated();
    }
    auth()->logout();
}

test('a confirmed sale delivered by the store credits points once and is traceable', function () {
    commerceProgram($this);
    $event = commerceEvent($this->buyer);   // $600 → 300 puntos
    signedCommerce($this, $event)->assertStatus(202)->assertJsonPath('estado', 'aceptado');
    signedCommerce($this, $event)->assertOk()->assertJsonPath('estado', 'duplicado');

    $id = (string) $this->buyer->getKey();
    $entry = PointsLedger::where('student_id', $id)->first();
    expect(PointsLedger::where('student_id', $id)->count())->toBe(1)
        ->and($entry->type)->toBe('earn')->and((int) $entry->amount)->toBe(300)
        ->and($entry->source_domain)->toBe('marketplace')->and($entry->source_reference)->toBe('CD3-0001');
    $webhook = WebhookEvent::first();
    expect(WebhookEvent::count())->toBe(1)->and($webhook->status)->toBe('processed')->and($webhook->source)->toBe('marketplace')
        ->and($webhook->event_type)->toBe('marketplace.order.confirmed.v1')->and($webhook->detail)->toContain('puntos')->and($webhook->reference)->toBe('CD3-0001')
        ->and(AnalyticsEvent::first()->processed)->toBeTrue()
        ->and(IntegrationLog::where('domain', 'marketplace')->where('direction', 'inbound')->where('status', 'success')->count())->toBe(1)
        ->and(AuditLog::where('action', 'points.earned')->count())->toBe(1);

    // El alumno lo ve en su saldo y la administración en la bandeja.
    $this->actingAs($this->buyer)->getJson('/api/v1/recompensas/points/my-balance')->assertOk()->assertJsonPath('balance', 300);
    $this->actingAs($this->auditor)->getJson('/api/v1/recompensas/integrations/webhooks')->assertOk()->assertJsonPath('data.0.status', 'processed');
});

test('a sale from a business outside the program or from a non student is recorded without points', function () {
    commerceProgram($this);
    signedCommerce($this, commerceEvent($this->buyer, folio: 'CD3-0002', business: 'BUS-AJENO'))->assertStatus(202);
    signedCommerce($this, commerceEvent($this->auditor, folio: 'CD3-0003'))->assertStatus(202);
    expect(PointsLedger::count())->toBe(0)
        ->and(WebhookEvent::where('status', 'ignored')->count())->toBe(2)
        ->and(WebhookEvent::where('status', 'failed')->count())->toBe(0);
});

test('a sale that cannot be processed stays in the tray and the administration retries it', function () {
    commerceProgram($this, withRule: false);
    signedCommerce($this, commerceEvent($this->buyer, folio: 'CD3-0004'))->assertStatus(202);
    $webhook = WebhookEvent::first();
    expect($webhook->status)->toBe('failed')->and($webhook->detail)->toContain('regla')->and(PointsLedger::count())->toBe(0);
    $url = '/api/v1/recompensas/integrations/webhooks/'.$webhook->getKey().'/retry';

    $this->actingAs($this->auditor)->postJson($url)->assertForbidden();
    $this->actingAs($this->buyer)->postJson($url)->assertForbidden();
    // Sin corregir la causa, el reintento vuelve a fallar y lo dice.
    $this->actingAs($this->program)->postJson($url)->assertStatus(422)->assertJsonPath('webhook.status', 'failed')->assertJsonPath('webhook.retry_count', 1);

    $this->postJson('/api/v1/recompensas/rules', ['business_id' => 'BUS-COM', 'name' => 'Souvenirs 1x2', 'type' => 'amount_spent', 'points_per_unit' => 1,
        'unit_amount' => 2, 'status' => 'active', 'active_from' => now()->subDay()->toDateString()])->assertCreated();
    $this->postJson($url)->assertOk()->assertJsonPath('webhook.status', 'processed')->assertJsonPath('webhook.retry_count', 2);
    expect((int) PointsLedger::where('student_id', (string) $this->buyer->getKey())->sum('amount'))->toBe(300)
        ->and(AuditLog::where('action', 'webhook.retried')->count())->toBe(2);
    // Un evento ya procesado no se reintenta.
    $this->postJson($url)->assertStatus(422);
    expect(PointsLedger::count())->toBe(1);
});

test('an approved return reverses the points of the sale', function () {
    commerceProgram($this);
    signedCommerce($this, commerceEvent($this->buyer, folio: 'CD3-0005'))->assertStatus(202);
    signedCommerce($this, commerceEvent($this->buyer, 'marketplace.order.refunded.v1', 'CD3-0005'))->assertStatus(202);
    signedCommerce($this, commerceEvent($this->buyer, 'marketplace.order.refunded.v1', 'CD3-0099'))->assertStatus(202);
    $id = (string) $this->buyer->getKey();
    expect((int) PointsLedger::where('student_id', $id)->sum('amount'))->toBe(0)
        ->and(PointsLedger::where('student_id', $id)->where('type', 'reverse')->count())->toBe(1)
        ->and(FraudFlag::count())->toBe(0)
        ->and(WebhookEvent::where('status', 'processed')->count())->toBe(2)
        ->and(WebhookEvent::where('status', 'ignored')->count())->toBe(1);
});

test('the integration health reports each domain with real figures', function () {
    commerceProgram($this);
    signedCommerce($this, commerceEvent($this->buyer, folio: 'CD3-0006'))->assertStatus(202);
    $health = $this->actingAs($this->auditor)->getJson('/api/v1/recompensas/integrations/health')->assertOk();
    $marketplace = collect($health->json('domains'))->firstWhere('domain', 'marketplace');
    $community = collect($health->json('domains'))->firstWhere('domain', 'community');
    expect($health->json('ingest_enabled'))->toBeTrue()->and($health->json('database'))->toBe('ok')
        ->and($marketplace['credential'])->toBeTrue()->and($marketplace['events_total'])->toBe(1)->and($marketplace['last_event_at'])->not->toBeNull()
        ->and($community['credential'])->toBeFalse()->and($community['events_total'])->toBe(0)
        ->and($health->json('webhooks'))->toMatchArray(['total' => 1, 'failed' => 0, 'processed' => 1]);
    $this->actingAs($this->buyer)->getJson('/api/v1/recompensas/integrations/health')->assertForbidden();
});

// ---------- Lado de Tienda ----------

function commerceOrder(User $buyer, string $folio): Order
{
    return Order::create(['folio' => $folio, 'buyer_id' => (string) $buyer->getKey(), 'business_id' => 'BUS-COM', 'items' => [], 'total' => 600, 'total_cents' => 60000,
        'currency' => 'MXN', 'payment_method' => 'wallet', 'payment_status' => 'pending', 'status' => 'awaiting_payment', 'idempotency_key' => 'k-'.$folio]);
}

test('the store queues one notice per confirmed sale and per approved return and delivers them signed', function () {
    $order = commerceOrder($this->buyer, 'CD3-0010');
    $outbox = fn () => DB::connection('marketplace')->getCollection(MarketplaceRewardsEvents::COLLECTION);
    expect($outbox()->countDocuments([]))->toBe(0);

    $order->update(['status' => 'payment_reconciliation', 'payment_status' => 'paid']);
    expect($outbox()->countDocuments([]))->toBe(0);          // pagado pero aún sin confirmar
    $order->update(['status' => 'confirmed']);
    $order->update(['status' => 'preparing']);
    $order->update(['status' => 'confirmed']);
    expect($outbox()->countDocuments([]))->toBe(1);          // un solo aviso por venta
    $order->update(['status' => 'return_approved', 'payment_status' => 'refund_pending']);
    expect($outbox()->countDocuments([]))->toBe(2);

    $sent = [];
    Http::fake(function ($request) use (&$sent) {
        $sent[] = $request;

        return Http::response(['estado' => 'aceptado', 'event_id' => $request->data()['event_id']], 202);
    });
    expect(Artisan::call('marketplace:publicar-recompensas'))->toBe(0);
    expect($sent)->toHaveCount(2)->and($outbox()->countDocuments(['status' => 'sent']))->toBe(2);
    $first = $sent[0];
    $time = $first->header('X-Platform-Timestamp')[0];
    expect($first->url())->toBe('http://app/api/v1/plataforma/eventos')
        ->and($first->header('X-Platform-Domain')[0])->toBe('marketplace')
        ->and($first->header('X-Platform-Signature')[0])->toBe(hash_hmac('sha256', $time.'|POST|/api/v1/plataforma/eventos|'.hash('sha256', $first->body()), 'test-marketplace-secret-0001'))
        ->and($first->data()['event_name'])->toBe('marketplace.order.confirmed.v1')
        ->and($first->data()['payload'])->toBe(['order_folio' => 'CD3-0010', 'business_id' => 'BUS-COM', 'buyer_id' => (string) $this->buyer->getKey(), 'total_cents' => 60000, 'currency' => 'MXN'])
        ->and($sent[1]->data()['event_name'])->toBe('marketplace.order.refunded.v1');

    // Lo que Tienda envió es exactamente lo que Recompensas acepta y convierte en puntos.
    commerceProgram($this);
    signedCommerce($this, $first->data())->assertStatus(202);
    expect((int) PointsLedger::where('student_id', (string) $this->buyer->getKey())->sum('amount'))->toBe(300);

    // Nada pendiente: una segunda pasada no reenvía.
    Artisan::call('marketplace:publicar-recompensas');
    expect($sent)->toHaveCount(2);
});

test('a failed delivery is kept and retried on the next pass', function () {
    commerceOrder($this->buyer, 'CD3-0011')->update(['status' => 'confirmed', 'payment_status' => 'paid']);
    $outbox = DB::connection('marketplace')->getCollection(MarketplaceRewardsEvents::COLLECTION);
    Http::fake(fn () => Http::response([], 503));
    expect(Artisan::call('marketplace:publicar-recompensas'))->toBe(1)
        ->and($outbox->countDocuments(['status' => 'failed', 'attempts' => 1]))->toBe(1);
    Http::swap(new Factory);
    Http::fake(fn ($request) => Http::response(['estado' => 'aceptado', 'event_id' => $request->data()['event_id']], 202));
    expect(Artisan::call('marketplace:publicar-recompensas'))->toBe(0)
        ->and($outbox->countDocuments(['status' => 'sent', 'attempts' => 2]))->toBe(1);
});

test('without the flag the store neither queues nor sends anything', function () {
    config(['marketplace.rewards.enabled' => false]);
    Http::fake();
    commerceOrder($this->buyer, 'CD3-0012')->update(['status' => 'confirmed', 'payment_status' => 'paid']);
    Artisan::call('marketplace:publicar-recompensas');
    expect(DB::connection('marketplace')->getCollection(MarketplaceRewardsEvents::COLLECTION)->countDocuments([]))->toBe(0);
    Http::assertNothingSent();
});
