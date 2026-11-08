<?php

use App\Models\StudentServices\Library\LibraryFine;
use App\Models\User;
use App\Services\StudentServices\Library\FineService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    $this->student = User::factory()->create();
    $this->fine = LibraryFine::create([
        'student_id' => (string) $this->student->getKey(), 'loan_id' => '507f1f77bcf86cd799439011',
        'type' => 'late', 'amount_cents' => 1500, 'reason' => 'Atraso', 'status' => 'pending', 'generated_at' => now(),
    ]);
    $this->web = '/servicios-estudiante/biblioteca/multas/'.$this->fine->id;
    $this->api = 'http://finance.test/api/v1/financial/student-services/library-fines/'.$this->fine->id;
    $this->checkout = '/finanzas/multas-biblioteca/solicitudes/'.Str::uuid();
    $this->payment = strtolower((string) Str::uuid());
    $this->status = 'PENDING';

    config(['integracion_servicios.finanzas_multas' => [
        'enabled' => true, 'url' => 'http://finance.test', 'client_id' => 'e5-fixture', 'client_secret' => 'fixture-secret',
    ]]);

    Http::preventStrayRequests();
    Http::fake([
        'http://finance.test/api/oauth/token' => function (Request $request) {
            expect($request['grant_type'])->toBe('client_credentials')
                ->and($request['client_id'])->toBe('e5-fixture')
                ->and($request['scope'])->toStartWith('financial:library-fine:');

            return Http::response(['access_token' => $request['scope'], 'expires_in' => 120]);
        },
        $this->api.'*' => function (Request $request) {
            $cancel = str_ends_with($request->url(), '/cancel');
            $prepare = $request->method() === 'POST' && ! $cancel;
            $scope = $cancel ? 'cancel' : ($prepare ? 'prepare' : 'read');
            expect($request->hasHeader('Authorization', 'Bearer financial:library-fine:'.$scope))->toBeTrue();
            if ($prepare) {
                expect($request->data())->toBe(['student_id' => $this->fine->student_id, 'amount_cents' => 1500, 'currency' => 'MXN'])
                    ->and($this->fine->fresh()->finance_requested)->toBeTrue();
            }

            if ($this->status === 'UNAVAILABLE') {
                return Http::response([], 503);
            }
            if ($this->status === 'CONNECTION_LOST') {
                return Http::failedConnection();
            }
            if ($this->status === 'MISSING') {
                return Http::response([], 404);
            }

            return Http::response(['data' => array_replace([
                'source_reference' => (string) $this->fine->id, 'student_id' => $this->fine->student_id,
                'concept' => 'library_fine', 'amount_cents' => 1500, 'currency' => 'MXN', 'status' => $this->status,
                'payment_id' => $this->status === 'CONFIRMED' ? $this->payment : null, 'confirmation_path' => $this->checkout,
            ], $this->overrides ?? [])]);
        },
    ]);
});

test('the owner starts payment with the stored amount and can reconcile it later', function () {
    $this->actingAs($this->student)->get('/servicios-estudiante/biblioteca/multas')
        ->assertOk()->assertInertia(fn ($page) => $page->where('fines.0.can_start_payment', true)->where('loans', []));
    $this->post($this->web.'/iniciar-pago', ['amount_cents' => 1])->assertUnprocessable();
    $this->withHeader('X-Inertia', 'true')->post($this->web.'/iniciar-pago')
        ->assertStatus(409)->assertHeader('X-Inertia-Location', url($this->checkout));
    expect($this->fine->fresh()->status)->toBe('pending');
    $this->post($this->web.'/consultar-pago')->assertRedirect();
    expect(app(FineService::class)->hasPendingFines($this->fine->student_id))->toBeTrue();

    $this->status = 'CONFIRMED';
    $this->post($this->web.'/consultar-pago')->assertRedirect()->assertSessionHasNoErrors();
    $this->post($this->web.'/consultar-pago')->assertRedirect()->assertSessionHasNoErrors();
    expect($this->fine->fresh()->verified_payment_id)->toBe($this->payment)
        ->and(app(FineService::class)->hasPendingFines($this->fine->student_id))->toBeFalse();
});

test('a different account cannot use either student payment action', function () {
    $this->actingAs(User::factory()->create())->post($this->web.'/iniciar-pago')->assertNotFound();
    $this->post($this->web.'/consultar-pago')->assertNotFound();
    Http::assertNothingSent();
});

test('a lost prepare response keeps the record recoverable and malformed confirmation stays pending', function () {
    $this->status = 'CONNECTION_LOST';
    $this->actingAs($this->student)->post($this->web.'/iniciar-pago')->assertSessionHasErrors('fine');
    expect($this->fine->fresh()->finance_requested)->toBeTrue();
    $this->status = 'CONFIRMED';
    $this->overrides = ['amount_cents' => 1499];
    $this->post($this->web.'/consultar-pago')->assertSessionHasErrors('fine');
    expect($this->fine->fresh()->status)->toBe('pending');
    $this->overrides = [];
    $this->post($this->web.'/consultar-pago')->assertSessionHasNoErrors();
    expect($this->fine->fresh()->status)->toBe('paid');
});

test('a prepared fine is closed only after Finance cancels and can retry safely', function () {
    $this->fine->forceFill(['finance_requested' => true])->save();
    $this->status = 'UNAVAILABLE';
    $service = app(FineService::class);
    expect(fn () => $service->waiveFine($this->fine))->toThrow(RuntimeException::class)
        ->and($this->fine->fresh()->status)->toBe('pending');
    $this->status = 'CANCELLED';
    $this->overrides = ['student_id' => null, 'amount_cents' => null, 'confirmation_path' => null];
    expect($service->waiveFine($this->fine)->status)->toBe('waived')
        ->and($service->waiveFine($this->fine)->status)->toBe('waived');
});

test('a confirmed payment wins over a staff cancellation and keeps the receipt', function () {
    $this->fine->forceFill(['finance_requested' => true])->save();
    $this->status = 'CONFIRMED';
    $result = app(FineService::class)->cancelFine($this->fine);
    expect($result->status)->toBe('paid')->and($result->verified_payment_id)->toBe($this->payment);
});

test('a locally closed fine cannot start and a disabled integration never prepares a charge', function () {
    $this->actingAs($this->student);
    config(['integracion_servicios.finanzas_multas.enabled' => false]);
    $this->post($this->web.'/iniciar-pago')->assertNotFound();
    expect(app(FineService::class)->cancelFine($this->fine)->status)->toBe('cancelled');
    config(['integracion_servicios.finanzas_multas.enabled' => true]);
    $this->post($this->web.'/iniciar-pago')->assertSessionHasErrors('fine');
    Http::assertNothingSent();
});
