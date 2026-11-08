<?php

use App\Models\Marketplace\AuditEvent;
use App\Models\Marketplace\Order;
use App\Models\Marketplace\PaymentIntent;
use App\Models\User;
use App\Services\Marketplace\MarketplaceOrders;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    $this->buyer = User::factory()->create();
    $this->expires = now()->addMinutes(5)->startOfSecond();
    $this->order = Order::create([
        'buyer_id' => (string) $this->buyer->getKey(), 'business_id' => 'BUS-CD-SOUV-001',
        'status' => 'awaiting_payment', 'payment_status' => 'pending', 'inventory_state' => 'reserved',
        'payment_method' => 'wallet', 'currency' => 'MXN', 'total_cents' => 17800, 'folio' => 'CD3-FIXTURE',
        'hold_expires_at' => $this->expires, 'reservation_id' => 'RES-FIXTURE',
        'reserve_payload' => ['items' => [['product_id' => 'TAZA-ID', 'location_id' => 'PASILLO-ID', 'variant_id' => null, 'quantity' => 2]]],
    ]);
    $this->intent = PaymentIntent::create(['order_id' => (string) $this->order->getKey(),
        'status' => 'pending_integration', 'provider' => 'pending_finance']);
    $this->requestId = (string) Str::uuid();
    $this->paymentId = (string) Str::uuid();
    $this->path = '/finanzas/compras/solicitudes/'.$this->requestId;
    $this->web = '/tienda/pedidos/'.$this->order->getKey();
    $this->finance = 'PENDING';
    $this->inventory = 'RESERVED';
    $this->confirmations = 0;
    $this->releases = 0;
    $this->prepared = 0;

    config(['marketplace.wallet_checkout' => ['enabled' => true, 'url' => 'http://e2.test', 'client_id' => 'e3-fixture', 'client_secret' => 'fixture-secret'],
        'marketplace.inventory' => ['url' => 'http://e4.test', 'secret' => 'fixture-inventory']]);

    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/api/oauth/token') {
            return Http::response(['access_token' => $request['scope'], 'expires_in' => 120]);
        }
        if (str_contains($request->url(), 'e2.test')) {
            $action = str_ends_with($path, '/cancel') ? 'cancel' : ($request->method() === 'GET' ? 'read' : 'prepare');
            expect($request->hasHeader('Authorization', 'Bearer financial:purchase:'.$action))->toBeTrue();
            if ($action === 'prepare') {
                $this->prepared++;
                expect($this->order->fresh()->wallet_checkout['client_id'])->toBe('e3-fixture')
                    ->and($request['order_id'])->toBe((string) $this->order->getKey())
                    ->and($request['amount_cents'])->toBe(17800)
                    ->and($request['items'])->toBe($this->order->reserve_payload['items'])
                    ->and($request['reservation_expires_at'])->toBe($this->expires->toISOString())
                    ->and($request->header('Idempotency-Key'))->toBe(['marketplace-wallet:'.$this->order->getKey()]);
            }
            if ($this->finance === 'UNKNOWN') {
                return Http::failedConnection();
            }
            if ($this->finance === 'MISSING') {
                return Http::response([], 404);
            }

            $status = $action === 'cancel' ? ($this->lockedFinance ?? $this->finance) : $this->finance;

            return Http::response(['data' => array_replace([
                'payment_request_id' => $this->requestId, 'order_id' => (string) $this->order->getKey(),
                'business_id' => $this->order->business_id, 'status' => $status, 'amount_cents' => 17800,
                'currency' => 'MXN', 'payment_intent_id' => $status === 'PAID' ? $this->paymentId : null,
                'expires_at' => $this->expires->toISOString(), 'confirmation_path' => $this->path,
            ], $this->overrides ?? [])]);
        }
        $id = $this->order->reservation_id;
        if (str_ends_with($path, '/'.$id.'/confirm')) {
            $this->confirmations++;
            expect($request['payment_intent_id'])->toBe($this->paymentId)
                ->and($request['idempotency_key'])->toBe('marketplace-confirm:'.$this->order->getKey());
            if ($this->awaitEvent ?? false) {
                return Http::response(['status' => 'REJECTED'], 409);
            }
            $this->inventory = 'CONFIRMED';
            if ($this->loseConfirmResponse ?? false) {
                return Http::failedConnection();
            }
        }
        if (str_ends_with($path, '/'.$id.'/release')) {
            $this->releases++;
            $this->inventory = 'RELEASED';
        }

        return Http::response(['reservation_id' => $id, 'order_id' => (string) $this->order->getKey(),
            'status' => $this->inventory, 'items' => $this->order->reserve_payload['items'],
            'expires_at' => $this->expires->toISOString(),
            'payment' => $this->inventory === 'CONFIRMED' ? ['payment_intent_id' => $this->paymentId, 'status' => 'PAID'] : null]);
    });
});

test('buyer reopens the original charge and the worker confirms stock after signed evidence arrives', function () {
    $this->actingAs($this->buyer);
    $this->withHeader('X-Inertia', 'true')->post($this->web.'/pagar-wallet')
        ->assertStatus(409)->assertHeader('X-Inertia-Location', url($this->path));
    $this->post($this->web.'/pagar-wallet')->assertStatus(409)->assertHeader('X-Inertia-Location', url($this->path));
    expect($this->prepared)->toBe(1)->and($this->order->fresh()->payment_status)->toBe('pending');

    $this->finance = 'PAID';
    $this->awaitEvent = true;
    $this->artisan('marketplace:reconciliar')->assertSuccessful();
    expect($this->order->fresh()->status)->toBe('payment_reconciliation')
        ->and($this->intent->fresh()->reference)->toBe($this->paymentId);
    $this->awaitEvent = false;
    $this->artisan('marketplace:reconciliar')->assertSuccessful();
    $this->artisan('marketplace:reconciliar')->assertSuccessful();
    expect($this->order->fresh()->status)->toBe('confirmed')->and($this->confirmations)->toBe(2)->and($this->releases)->toBe(0);
});

test('a lost confirmation response recovers the existing sale without sending another confirmation', function () {
    $this->actingAs($this->buyer)->post($this->web.'/pagar-wallet')->assertRedirect(url($this->path));
    $this->finance = 'PAID';
    $this->loseConfirmResponse = true;
    $this->artisan('marketplace:reconciliar')->assertSuccessful();
    expect($this->order->fresh()->status)->toBe('payment_reconciliation')->and($this->inventory)->toBe('CONFIRMED');
    $this->artisan('marketplace:reconciliar')->assertSuccessful();
    expect($this->order->fresh()->status)->toBe('confirmed')->and($this->confirmations)->toBe(1);
});

test('a lost response and an unproven cancellation preserve the reservation until a terminal result', function () {
    $this->finance = 'UNKNOWN';
    $this->actingAs($this->buyer)->post($this->web.'/pagar-wallet')->assertSessionHasErrors('payment');
    $this->finance = 'MISSING';
    $this->post($this->web.'/estado', ['status' => 'cancelled'])->assertRedirect()
        ->assertSessionHas('success', 'Cancelación solicitada. Esperamos la confirmación de Finanzas.');
    $audit = AuditEvent::where('action', 'order_status_changed')->sole();
    expect($this->order->fresh()->inventory_state)->toBe('reserved')->and($this->releases)->toBe(0)
        ->and($audit->details['status'])->toBe('awaiting_payment')
        ->and($audit->details['requested_status'])->toBe('cancelled');
    $this->finance = 'CANCELLED';
    $this->artisan('marketplace:reconciliar')->assertSuccessful();
    expect($this->order->fresh()->status)->toBe('cancelled')->and($this->releases)->toBe(1);
});

test('a paid order with a released reservation retains evidence and enters refund review', function () {
    $this->actingAs($this->buyer)->post($this->web.'/pagar-wallet')->assertRedirect(url($this->path));
    $this->finance = 'PAID';
    $this->inventory = 'RELEASED';
    app(MarketplaceOrders::class)->cancel($this->order, (string) $this->buyer->getKey());
    expect($this->order->fresh()->status)->toBe('refund_review')->and($this->order->fresh()->payment_status)->toBe('paid')
        ->and($this->intent->fresh()->reference)->toBe($this->paymentId)->and($this->releases)->toBe(0);
});

test('expired financial request releases stock even if the inventory deadline has not arrived', function () {
    $this->actingAs($this->buyer)->post($this->web.'/pagar-wallet')->assertRedirect(url($this->path));
    $this->finance = 'EXPIRED';
    $this->artisan('marketplace:reconciliar')->assertSuccessful();
    expect($this->order->fresh()->status)->toBe('expired')->and($this->releases)->toBe(1);
});

test('clock expiry cannot release stock if the locked financial cancellation finds a completed charge', function () {
    $this->actingAs($this->buyer)->post($this->web.'/pagar-wallet')->assertRedirect(url($this->path));
    $this->finance = 'EXPIRED';
    $this->lockedFinance = 'PAID';
    $this->artisan('marketplace:reconciliar')->assertSuccessful();
    expect($this->order->fresh()->status)->toBe('confirmed')->and($this->releases)->toBe(0);
});

test('foreign accounts and untrusted redirects cannot use the checkout', function () {
    $this->actingAs(User::factory()->create())->post($this->web.'/pagar-wallet')->assertNotFound();
    Http::assertNothingSent();
    $this->overrides = ['confirmation_path' => 'https://attacker.test/'.$this->requestId];
    $this->actingAs($this->buyer)->post($this->web.'/pagar-wallet')->assertSessionHasErrors('payment');
    expect($this->order->fresh()->payment_status)->toBe('pending');
});
