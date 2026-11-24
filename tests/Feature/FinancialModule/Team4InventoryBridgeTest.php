<?php

// P-14: el aviso de pagos de Finanzas a Inventarios. Cada lado tiene sus pruebas contra un interlocutor simulado
// (Team4PaymentOutboxTest, InventoryPaymentEventsTest). Aquí se conectan de verdad: lo que Finanzas firma y envía entra
// por la ruta real del receptor de Inventarios, con sus cabeceras y sus bytes, y con ese aviso la reserva de un pedido
// puede convertirse en venta. Ningún pago real: sólo las bases de prueba.

use App\Domains\Financial\Integrations\Team4\PaymentEventOutbox;
use App\Domains\Financial\Integrations\Team4\PaymentEventPayload;
use App\Domains\Financial\Integrations\Team4\Team4WebhookDelivery;
use App\Domains\Financial\Integrations\Team4\WebhookSigner;
use App\Models\InventoryModule\InventoryBusiness;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

const BRIDGE_PATH = '/api/equipo4/webhooks/payment-status';
const BRIDGE_SECRET = 'bridge-fixture-not-a-real-team-secret';

beforeEach(function () {
    config(['database.connections.sqlsrv.database' => 'campus_digital_financial_testing']);
    DB::purge('sqlsrv');
    if (! app()->environment('testing') || config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing'
        || config('database.connections.mongodb.database') !== 'campus_virtual_testing') {
        throw new RuntimeException('Solo bases de pruebas.');
    }
    DB::connection('sqlsrv')->beginTransaction();
    config(['financial_team4.enabled' => true, 'financial_team4.url' => 'http://inventory.test'.BRIDGE_PATH,
        'financial_team4.secret' => BRIDGE_SECRET, 'financial_team4.pilot_client_id' => null,
        'team4.business_id' => 'bridge-A', 'team4.integration.business_id' => 'bridge-A',
        'team4.integration.payment_events_enabled' => true,
        'team4.integration.secrets' => ['equipo3' => 'bridge-E3', 'equipo2' => BRIDGE_SECRET, 'equipo7' => 'bridge-E7']]);
    InventoryBusiness::create(['business_id' => 'bridge-A', 'name' => 'Negocio puente', 'active' => true]);
    (require database_path('migrations/2026_10_14_000000_create_inventory_domain_indexes.php'))->up();
    $migration = database_path('migrations/2026_10_17_000200_inventory_payment_events_v2.php');
    if (is_file($migration)) {
        (require $migration)->up();
    }
    Artisan::call('team4:init-indexes');
    Artisan::call('inventario:demo');

    Http::preventStrayRequests();
    $this->answers = [];        // lo que respondió Inventarios a cada envío
    $this->loseAnswer = false;  // true: Inventarios recibe y procesa, pero la respuesta no llega a Finanzas
    Http::fake(['inventory.test/*' => function ($request) {
        $response = $this->call('POST', BRIDGE_PATH, [], [], [], [
            'HTTP_X_TEAM_TIMESTAMP' => $request->header('X-Team-Timestamp')[0] ?? '',
            'HTTP_X_TEAM_SIGNATURE' => $request->header('X-Team-Signature')[0] ?? '',
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $request->body());
        $this->answers[] = $response->getStatusCode();
        if ($this->loseAnswer) {
            $this->loseAnswer = false;
            throw new ConnectionException('respuesta perdida');
        }

        return Http::response($response->getContent(), $response->getStatusCode(), ['Content-Type' => 'application/json']);
    }]);
});

afterEach(function () {
    while (DB::connection('sqlsrv')->transactionLevel() > 0) {
        DB::connection('sqlsrv')->rollBack();
    }
});

function bridgeSigned($test, string $path, array $body)
{
    $timestamp = (string) time();

    return $test->postJson($path, $body, ['X-Team-Timestamp' => $timestamp,
        'X-Team-Signature' => hash_hmac('sha256', $timestamp.'|POST|'.$path.'|'.hash('sha256', json_encode($body)), 'bridge-E3')]);
}

function bridgeLine(int $quantity = 2): array
{
    $row = DB::connection('inventory')->getCollection('inventories')->findOne(['business_id' => 'bridge-A', 'available' => ['$gt' => 10]]);

    return ['product_id' => (string) $row['product_id'], 'location_id' => (string) $row['location_id'], 'variant_id' => null, 'quantity' => $quantity];
}

function bridgeStock(array $line): array
{
    $row = (array) DB::connection('inventory')->getCollection('inventories')
        ->findOne(['business_id' => 'bridge-A', 'product_id' => $line['product_id'], 'location_id' => $line['location_id']]);

    return ['on_hand' => (int) $row['on_hand'], 'available' => (int) $row['available'], 'reserved' => (int) $row['reserved']];
}

function bridgePaid(string $order, string $intent, int $cents = 24000): string
{
    return app(PaymentEventOutbox::class)->record('paid-'.Str::uuid(), ['payment_intent_id' => $intent, 'order_id' => $order,
        'business_id' => 'bridge-A', 'status' => 'PAID', 'amount_cents' => $cents, 'occurred_at' => '2026-11-16T12:00:00Z']);
}

function bridgeOutbox(string $eventId): object
{
    return DB::connection('sqlsrv')->table('financial_team4_outbox')->where('event_id', $eventId)->first();
}

function bridgeEvents(): int
{
    return DB::connection('inventory')->getCollection('payment_events')->countDocuments(['business_id' => 'bridge-A']);
}

test('a payment recorded by Finanzas is verified and stored by Inventarios, and only then the reserved stock becomes a sale', function () {
    $line = bridgeLine();
    $before = bridgeStock($line);
    $reservation = bridgeSigned($this, '/api/equipo4/integration/reservations',
        ['order_id' => 'ORDER-BRIDGE-1', 'items' => [$line], 'idempotency_key' => 'bridge-reserve-0001'])->assertCreated()->json('reservation_id');
    $confirm = ['order_id' => 'ORDER-BRIDGE-1', 'payment_intent_id' => 'PI-BRIDGE-1', 'idempotency_key' => 'bridge-confirm-0001'];

    // Sin aviso de pago, Inventarios no convierte la reserva en venta (es lo que deja el pedido cobrado y sin confirmar).
    bridgeSigned($this, '/api/equipo4/integration/reservations/'.$reservation.'/confirm', $confirm)->assertStatus(409);
    expect(bridgeStock($line))->toBe(['on_hand' => $before['on_hand'], 'available' => $before['available'] - 2, 'reserved' => $before['reserved'] + 2]);

    $event = bridgePaid('ORDER-BRIDGE-1', 'PI-BRIDGE-1');
    expect(app(Team4WebhookDelivery::class)->run())->toBe(1)
        ->and($this->answers)->toBe([202])                                   // firma y cuerpo aceptados por el receptor real
        ->and(bridgeOutbox($event)->state)->toBe('DELIVERED');

    $stored = (array) DB::connection('inventory')->getCollection('payment_events')->findOne(['payment_intent_id' => 'PI-BRIDGE-1']);
    expect($stored['status'])->toBe('PAID')->and($stored['order_id'])->toBe('ORDER-BRIDGE-1')->and($stored['business_id'])->toBe('bridge-A')
        ->and((float) $stored['amount'])->toBe(240.0)->and($stored['event_id'])->toBe($event);

    // Con el aviso guardado, la misma confirmación convierte la reserva en venta, una sola vez.
    bridgeSigned($this, '/api/equipo4/integration/reservations/'.$reservation.'/confirm', $confirm)->assertSuccessful()->assertJsonPath('status', 'CONFIRMED');
    bridgeSigned($this, '/api/equipo4/integration/reservations/'.$reservation.'/confirm', $confirm)->assertSuccessful()->assertJsonPath('status', 'CONFIRMED');

    expect(bridgeStock($line))->toBe(['on_hand' => $before['on_hand'] - 2, 'available' => $before['available'] - 2, 'reserved' => $before['reserved']]);
    $sales = DB::connection('inventory')->getCollection('stock_movements')
        ->countDocuments(['business_id' => 'bridge-A', 'product_id' => $line['product_id'], 'type' => 'SALE']);
    expect($sales)->toBe(1);
});

test('a notice signed with another secret or altered on the way is rejected by Inventarios and nothing is stored', function () {
    $event = bridgePaid('ORDER-BRIDGE-2', 'PI-BRIDGE-2');

    // Otro secreto: el receptor no lo reconoce. Finanzas lo deja para revisión de un operador; no insiste a ciegas.
    config(['financial_team4.secret' => 'otro-secreto-que-inventarios-no-conoce']);
    expect(app(Team4WebhookDelivery::class)->run())->toBe(1)
        ->and($this->answers[0])->toBeIn([401, 403])
        ->and(bridgeOutbox($event)->state)->toBe('REVIEW')
        ->and(bridgeEvents())->toBe(0);

    // Firma válida de Finanzas sobre un cuerpo, pero llega otro cuerpo (importe cambiado): la firma ya no corresponde.
    $body = bridgeOutbox($event)->body;
    $headers = app(WebhookSigner::class)->headers($body, BRIDGE_PATH, time(), BRIDGE_SECRET);
    $altered = str_replace('"amount":240.00', '"amount":1.00', $body);
    expect($altered)->not->toBe($body);
    $this->call('POST', BRIDGE_PATH, [], [], [], ['HTTP_X_TEAM_TIMESTAMP' => $headers['X-Team-Timestamp'], 'HTTP_X_TEAM_SIGNATURE' => $headers['X-Team-Signature'],
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $altered)->assertStatus(401);

    // Sin firma.
    $this->call('POST', BRIDGE_PATH, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)->assertStatus(401);
    expect(bridgeEvents())->toBe(0);

    // El mismo cuerpo con su firma sí entra.
    $this->call('POST', BRIDGE_PATH, [], [], [], ['HTTP_X_TEAM_TIMESTAMP' => $headers['X-Team-Timestamp'], 'HTTP_X_TEAM_SIGNATURE' => $headers['X-Team-Signature'],
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)->assertStatus(202);
    expect(bridgeEvents())->toBe(1);
});

test('a failed delivery is retried with the same bytes, and a lost answer does not duplicate the payment in Inventarios', function () {
    $event = bridgePaid('ORDER-BRIDGE-3', 'PI-BRIDGE-3');
    $bytes = bridgeOutbox($event)->body;

    // Entrega fallida: el receptor está apagado y responde 503. Finanzas la deja para reintento.
    config(['team4.integration.payment_events_enabled' => false]);
    expect(app(Team4WebhookDelivery::class)->run())->toBe(1)
        ->and($this->answers)->toBe([503])
        ->and(bridgeOutbox($event)->state)->toBe('RETRY')
        ->and(bridgeEvents())->toBe(0);
    expect(app(Team4WebhookDelivery::class)->run())->toBe(0);   // todavía no toca: respeta la espera

    // Reintento: Inventarios lo recibe y lo guarda, pero la respuesta se pierde. Finanzas no lo da por entregado.
    config(['team4.integration.payment_events_enabled' => true]);
    $this->travel(10)->seconds();
    $this->loseAnswer = true;
    expect(app(Team4WebhookDelivery::class)->run())->toBe(1)
        ->and(bridgeOutbox($event)->state)->toBe('RETRY')
        ->and(bridgeEvents())->toBe(1);

    // Segundo reintento, con los mismos bytes: Inventarios reconoce el evento repetido y no crea otro.
    $this->travel(60)->seconds();
    expect(app(Team4WebhookDelivery::class)->run())->toBe(1)
        ->and($this->answers)->toBe([503, 202, 202])
        ->and(bridgeOutbox($event)->state)->toBe('DELIVERED')
        ->and(bridgeOutbox($event)->body)->toBe($bytes)
        ->and((int) bridgeOutbox($event)->attempts)->toBe(3)
        ->and(bridgeEvents())->toBe(1);
    expect(app(Team4WebhookDelivery::class)->run())->toBe(0);   // entregado: no se vuelve a enviar
});

test('an event that arrives late, behind a newer one of the same payment, is rejected and does not change what Inventarios knows', function () {
    $canonical = PaymentEventPayload::canonical(['payment_intent_id' => 'PI-BRIDGE-4', 'order_id' => 'ORDER-BRIDGE-4', 'business_id' => 'bridge-A',
        'status' => 'PAID', 'amount_cents' => 24000, 'occurred_at' => '2026-11-16T12:00:00Z']);
    $send = function (string $eventId, int $version) use ($canonical) {
        $body = PaymentEventPayload::body($canonical, $eventId, $version);
        $headers = app(WebhookSigner::class)->headers($body, BRIDGE_PATH, time(), BRIDGE_SECRET);

        return $this->call('POST', BRIDGE_PATH, [], [], [], ['HTTP_X_TEAM_TIMESTAMP' => $headers['X-Team-Timestamp'],
            'HTTP_X_TEAM_SIGNATURE' => $headers['X-Team-Signature'], 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body);
    };

    $send('evt-bridge-newer', 3)->assertStatus(202);
    $send('evt-bridge-late', 2)->assertStatus(409);           // tardío: versión anterior a la ya recibida
    $send('evt-bridge-newer', 3)->assertStatus(202)->assertJsonPath('idempotent', true);   // el mismo evento repetido no molesta

    $latest = (array) DB::connection('inventory')->getCollection('payment_events')
        ->findOne(['payment_intent_id' => 'PI-BRIDGE-4'], ['sort' => ['event_version' => -1]]);
    expect(bridgeEvents())->toBe(1)->and($latest['event_id'])->toBe('evt-bridge-newer')->and((int) $latest['event_version'])->toBe(3);
});
