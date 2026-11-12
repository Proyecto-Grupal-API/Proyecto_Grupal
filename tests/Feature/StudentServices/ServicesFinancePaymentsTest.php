<?php

use App\Models\StudentServices\Lockers\Locker;
use App\Models\StudentServices\Lockers\LockerAssignment;
use App\Models\StudentServices\Lockers\LockerPeriod;
use App\Models\StudentServices\Lockers\LockerRequest;
use App\Models\StudentServices\Services\ServiceOrder;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
 * Pago de lockers e impresiones con la wallet de Finanzas. Finanzas se
 * simula por HTTP con el contrato real: /api/v1/financial/student-services/...
 */
beforeEach(function () {
    useStudentServicesTestDatabase();
    $this->withoutVite();

    config(['integracion_servicios.finanzas_servicios' => [
        'enabled' => true, 'url' => 'http://finance.test', 'client_id' => 'e5-fixture', 'client_secret' => 'fixture-secret',
    ]]);

    $this->student = User::factory()->create();
    $this->finance = [];      // concepto/origen => respuesta simulada
    $this->sent = [];         // peticiones recibidas por "Finanzas"

    Http::preventStrayRequests();
    Http::fake(function (HttpRequest $request) {
        if (str_ends_with($request->url(), '/api/oauth/token')) {
            expect($request['client_id'])->toBe('e5-fixture')
                ->and($request['scope'])->toStartWith('financial:student-service:');

            return Http::response(['access_token' => $request['scope'], 'expires_in' => 120]);
        }

        $this->sent[] = $request;
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        preg_match('~/payments/([a-z_]+)/([a-f0-9]{24})(/cancel)?$~', $path, $m);
        $key = $m[1].'/'.$m[2];
        $scope = isset($m[3]) ? 'cancel' : ($request->method() === 'POST' ? 'prepare' : 'read');
        expect($request->hasHeader('Authorization', 'Bearer financial:student-service:'.$scope))->toBeTrue();

        if ($scope === 'prepare' && ! isset($this->finance[$key])) {
            $this->finance[$key] = [
                'source_reference' => $m[2], 'concept' => $m[1],
                'student_id' => $request['student_id'], 'amount_cents' => $request['amount_cents'], 'currency' => 'MXN',
                'status' => 'PENDING', 'payment_id' => null,
                'confirmation_path' => '/finanzas/servicios-estudiante/solicitudes/'.Str::uuid(),
            ];
        }

        if ($scope === 'cancel' && $this->finance[$key]['status'] === 'PENDING') {
            $this->finance[$key]['status'] = 'CANCELLED';
        }

        return isset($this->finance[$key])
            ? Http::response(['data' => $this->finance[$key]])
            : Http::response([], 404);
    });
});

/** Simula que el alumno autorizó el cobro en la pantalla de Finanzas. */
function financeConfirms(object $test, string $key): void
{
    $test->finance[$key]['status'] = 'CONFIRMED';
    $test->finance[$key]['payment_id'] = (string) Str::uuid();
}

function financeLockerSetup(): LockerPeriod
{
    Locker::create(['code' => 'L-F1', 'qr_code' => 'QR-L-F1', 'building' => 'A', 'zone' => '1', 'size' => 'small', 'status' => 'available']);

    return LockerPeriod::create([
        'code' => '2026-F', 'name' => 'Periodo con Finanzas', 'starts_at' => now()->subWeek(), 'ends_at' => now()->addMonths(3),
        'prices_cents' => ['small' => 15050, 'medium' => 22000, 'large' => 30000], 'status' => 'active',
    ]);
}

test('a locker request is paid with the wallet and the locker is assigned when Finance confirms', function () {
    $period = financeLockerSetup();
    $this->actingAs($this->student)->post('/servicios-estudiante/lockers/solicitudes', ['period_id' => (string) $period->id, 'size' => 'small'])
        ->assertSessionHasNoErrors();
    $request = LockerRequest::firstOrFail();
    $web = '/servicios-estudiante/lockers/solicitudes/'.$request->id;

    $this->get('/servicios-estudiante/lockers/solicitudes')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('requests.0.can_start_payment', true)->where('requests.0.can_reconcile_payment', false));

    $this->post($web.'/iniciar-pago', ['amount_cents' => 1])->assertUnprocessable();
    $key = 'locker_rental/'.$request->id;
    $this->withHeader('X-Inertia', 'true')->post($web.'/iniciar-pago')
        ->assertStatus(409)->assertHeader('X-Inertia-Location', url($this->finance[$key]['confirmation_path']));
    expect($this->finance[$key]['amount_cents'])->toBe(15050)
        ->and($this->finance[$key]['student_id'])->toBe((string) $this->student->id)
        ->and($request->fresh()->finance_requested)->toBeTrue();

    // Aún sin autorizar en Finanzas.
    $this->post($web.'/consultar-pago')->assertSessionHasErrors('locker_request');
    expect($request->fresh()->status)->toBe('pending');

    financeConfirms($this, $key);
    $this->post($web.'/consultar-pago')->assertSessionHasNoErrors();

    expect($request->fresh()->payment_reference)->toBe($this->finance[$key]['payment_id'])
        ->and(LockerAssignment::count())->toBe(1)
        ->and(Locker::firstOrFail()->status)->toBe('occupied');
});

test('another student cannot pay someone else request and a request paid in Finance is not cancelled', function () {
    $period = financeLockerSetup();
    $this->actingAs($this->student)->post('/servicios-estudiante/lockers/solicitudes', ['period_id' => (string) $period->id, 'size' => 'small']);
    $request = LockerRequest::firstOrFail();
    $web = '/servicios-estudiante/lockers/solicitudes/'.$request->id;

    $this->actingAs(User::factory()->create())->post($web.'/iniciar-pago')->assertNotFound();

    $this->actingAs($this->student)->withHeader('X-Inertia', 'true')->post($web.'/iniciar-pago')->assertStatus(409);
    financeConfirms($this, 'locker_rental/'.$request->id);

    // El alumno pagó en Finanzas y luego intenta cancelar: se registra el pago.
    $this->withHeaders(['X-Inertia' => ''])->patch($web.'/cancelar')->assertSessionHasErrors();
    expect($request->fresh()->status)->toBeIn(['paid', 'assigned']);
});

test('a pending request that never reached Finance is cancelled without calling it', function () {
    $period = financeLockerSetup();
    $this->actingAs($this->student)->post('/servicios-estudiante/lockers/solicitudes', ['period_id' => (string) $period->id, 'size' => 'small']);
    $request = LockerRequest::firstOrFail();

    $this->patch('/servicios-estudiante/lockers/solicitudes/'.$request->id.'/cancelar')->assertSessionHasNoErrors();

    expect($request->fresh()->status)->toBe('cancelled')
        ->and($this->sent)->toBe([]);
});

test('a print order is paid with the wallet and moves to processing', function () {
    $order = ServiceOrder::create([
        'folio' => 'SRV-F-1', 'student_id' => (string) $this->student->id, 'service_type' => 'printing', 'quantity' => 10,
        'quoted_amount_cents' => 1200, 'payment_status' => 'pending', 'status' => 'awaiting_payment', 'requested_at' => now(),
    ]);
    $web = '/servicios-estudiante/servicios-impresiones/'.$order->id;

    $this->actingAs($this->student)->get('/servicios-estudiante/servicios-impresiones')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('orders.0.canStartPayment', true));
    $this->withHeader('X-Inertia', 'true')->post($web.'/iniciar-pago')->assertStatus(409);
    $key = 'print_order/'.$order->id;
    expect($this->finance[$key]['amount_cents'])->toBe(1200);

    financeConfirms($this, $key);
    $this->withHeaders(['X-Inertia' => ''])->post($web.'/consultar-pago')->assertSessionHasNoErrors();

    expect($order->fresh()->payment_status)->toBe('paid')
        ->and($order->fresh()->status)->toBe('processing')
        ->and($order->fresh()->payment_reference_id)->toBe($this->finance[$key]['payment_id']);
});

/** Peticiones de cancelación que recibió "Finanzas". */
function financeCancelCalls(object $test): int
{
    return count(array_filter($test->sent, fn (HttpRequest $request) => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/cancel')));
}

test('a locker request sent to Finance stays pending if the integration is turned off before cancelling', function () {
    $period = financeLockerSetup();
    $this->actingAs($this->student)->post('/servicios-estudiante/lockers/solicitudes', ['period_id' => (string) $period->id, 'size' => 'small']);
    $request = LockerRequest::firstOrFail();
    $web = '/servicios-estudiante/lockers/solicitudes/'.$request->id;
    $key = 'locker_rental/'.$request->id;

    $this->withHeader('X-Inertia', 'true')->post($web.'/iniciar-pago')->assertStatus(409);
    expect($request->fresh()->finance_requested)->toBeTrue();

    // Se apaga la integración con el cobro ya preparado: no se cancela a ciegas.
    config(['integracion_servicios.finanzas_servicios.enabled' => false]);
    $this->withHeaders(['X-Inertia' => ''])->patch($web.'/cancelar')->assertSessionHasErrors('status');

    expect($request->fresh()->status)->toBe('pending')
        ->and($request->fresh()->finance_requested)->toBeTrue()
        ->and(financeCancelCalls($this))->toBe(0);

    // Al volver a encender, Finanzas confirma el cobro y E5 lo registra.
    config(['integracion_servicios.finanzas_servicios.enabled' => true]);
    financeConfirms($this, $key);
    $this->post($web.'/consultar-pago')->assertSessionHasNoErrors();

    expect($request->fresh()->payment_reference)->toBe($this->finance[$key]['payment_id'])
        ->and(LockerAssignment::count())->toBe(1);
});

test('a print order sent to Finance is only closed after Finance confirms the cancellation', function () {
    $order = ServiceOrder::create([
        'folio' => 'SRV-F-2', 'student_id' => (string) $this->student->id, 'service_type' => 'printing', 'quantity' => 5,
        'quoted_amount_cents' => 600, 'payment_status' => 'pending', 'status' => 'awaiting_payment', 'requested_at' => now(),
    ]);
    $web = '/servicios-estudiante/servicios-impresiones/'.$order->id;
    $key = 'print_order/'.$order->id;

    $this->actingAs($this->student)->withHeader('X-Inertia', 'true')->post($web.'/iniciar-pago')->assertStatus(409);

    config(['integracion_servicios.finanzas_servicios.enabled' => false]);
    $this->withHeaders(['X-Inertia' => ''])->patch($web.'/cancelar')->assertSessionHasErrors('order');

    // Tampoco se paga con beca encima del cobro de Finanzas.
    $this->patch($web.'/pagar-con-beca')->assertSessionHasErrors('order');
    expect(session('errors')->first('order'))->toContain('Finanzas');

    expect($order->fresh()->status)->toBe('awaiting_payment')
        ->and($order->fresh()->payment_status)->toBe('pending')
        ->and(financeCancelCalls($this))->toBe(0);

    // Con la integración encendida, Finanzas confirma la cancelación y E5 cierra la orden.
    config(['integracion_servicios.finanzas_servicios.enabled' => true]);
    $this->patch($web.'/cancelar')->assertSessionHasNoErrors();

    expect($this->finance[$key]['status'])->toBe('CANCELLED')
        ->and($order->fresh()->status)->toBe('cancelled')
        ->and(financeCancelCalls($this))->toBe(1);
});
