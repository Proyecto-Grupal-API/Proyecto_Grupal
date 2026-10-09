<?php

use App\Models\StudentServices\Audit\ServiceAuditLog;
use App\Models\StudentServices\Benefits\BenefitAssignment;
use App\Models\StudentServices\Lockers\Locker;
use App\Models\StudentServices\Lockers\LockerAssignment;
use App\Models\StudentServices\Lockers\LockerPeriod;
use App\Models\StudentServices\ServiceAccess\ServiceCheckin;
use App\Models\StudentServices\Services\ServiceOrder;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    useStudentServicesTestDatabase();
    $this->withoutVite();

    $this->travelTo(Carbon::parse('2026-10-12 09:00'));

    config(['services.student_services_api.clients' => [
        ['id' => 'equipo6-comunidad', 'token' => 'token-equipo6', 'scopes' => 'services:benefits:read services:benefits:write'],
        ['id' => 'solo-lectura', 'token' => 'token-lectura', 'scopes' => 'services:benefits:read'],
        ['id' => 'otro-cliente', 'token' => 'token-otro', 'scopes' => 'services:benefits:read services:benefits:write'],
    ]]);

    $this->period = LockerPeriod::create([
        'code' => '2026-B',
        'name' => 'Agosto - Diciembre 2026',
        'starts_at' => Carbon::parse('2026-08-01'),
        'ends_at' => Carbon::parse('2027-01-01'),
        'prices_cents' => ['small' => 15000],
        'status' => 'active',
    ]);

    $this->student = User::factory()->create();
});

function createLocker(string $code, string $building = 'A'): Locker
{
    return Locker::create([
        'code' => $code,
        'qr_code' => 'QR-'.$code,
        'building' => $building,
        'zone' => 'PB',
        'size' => 'small',
        'status' => 'available',
    ]);
}

function benefitPayload(User $student, array $overrides = []): array
{
    return [
        'beneficiario_id' => (string) $student->id,
        'organizacion_id' => 'org-sistemas',
        'convocatoria_id' => 'conv-1',
        'solicitud_id' => 'sol-'.$student->id,
        'folio' => 'BEC-0001',
        'tipo' => 'locker',
        'cantidad' => 1,
        'vigencia_inicio' => '2026-10-12T00:00:00-06:00',
        'vigencia_fin_exclusiva' => '2026-12-20T00:00:00-06:00',
        ...$overrides,
    ];
}

function postBenefit(array $payload, string $key, string $token = 'token-equipo6')
{
    return test()->withHeaders([
        'Authorization' => "Bearer {$token}",
        'Idempotency-Key' => $key,
    ])->postJson('/api/v1/servicios/asignaciones', $payload);
}

test('requests without a valid service token or scope are rejected in the agreed error format', function () {
    $this->getJson('/api/v1/servicios/beneficios')
        ->assertUnauthorized()
        ->assertJsonStructure(['codigo', 'mensaje', 'correlacion_id'])
        ->assertJsonPath('codigo', 'no_autenticado');

    postBenefit(benefitPayload($this->student), 'beca:1', 'token-lectura')
        ->assertForbidden()
        ->assertJsonPath('codigo', 'sin_permiso');
});

test('the catalog only offers locker and prints', function () {
    $this->withToken('token-equipo6')
        ->getJson('/api/v1/servicios/beneficios')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.beneficio_id', 'locker')
        ->assertJsonPath('data.0.vigencia.periodos.0.codigo', '2026-B')
        ->assertJsonPath('data.1.beneficio_id', 'impresiones')
        ->assertHeader('X-Correlation-Id');
});

test('availability is informative and suggests other buildings', function () {
    createLocker('LKR-A-001', 'A');
    createLocker('LKR-B-001', 'B');
    createLocker('LKR-B-002', 'B');

    $this->withToken('token-equipo6')
        ->getJson('/api/v1/servicios/disponibilidad?beneficio_id=locker&inicio=2026-10-12&fin=2026-12-20&cantidad=2&edificio=A')
        ->assertOk()
        ->assertJsonPath('data.disponible', false)
        ->assertJsonPath('data.cantidad_disponible', 1)
        ->assertJsonPath('data.alternativas.0.edificio', 'B')
        ->assertJsonPath('data.periodo.codigo', '2026-B');

    expect(Locker::where('status', 'available')->count())->toBe(3);
});

test('a locker scholarship assigns a real locker and is idempotent', function () {
    createLocker('LKR-A-001');

    $first = postBenefit(benefitPayload($this->student), 'beca:sol-1')
        ->assertCreated()
        ->assertJsonPath('data.estado', 'asignado')
        ->assertJsonPath('data.recurso.codigo', 'LKR-A-001')
        ->assertJsonPath('data.vigencia.fin_exclusiva', '2027-01-01T00:00:00-06:00')
        ->assertJsonPath('meta.repetida', false);

    postBenefit(benefitPayload($this->student), 'beca:sol-1')
        ->assertOk()
        ->assertJsonPath('data.asignacion_id', $first->json('data.asignacion_id'))
        ->assertJsonPath('meta.repetida', true);

    expect(BenefitAssignment::count())->toBe(1)
        ->and(LockerAssignment::where('source', 'scholarship')->count())->toBe(1)
        ->and(Locker::first()->status)->toBe('occupied')
        ->and(ServiceAuditLog::where('action', 'benefit.assignment.created')->count())->toBe(1)
        ->and(ServiceAuditLog::where('action', 'benefit.assignment.created')->value('actor_id'))->toBe('api:equipo6-comunidad');
});

test('reusing a key with different content is a conflict', function () {
    createLocker('LKR-A-001');

    postBenefit(benefitPayload($this->student), 'beca:sol-1')->assertCreated();

    postBenefit(benefitPayload($this->student, ['convocatoria_id' => 'otra']), 'beca:sol-1')
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'conflicto_idempotencia');
});

test('the idempotency key can come in the body as Comunidad already generates it', function () {
    createLocker('LKR-A-001');

    $this->withToken('token-equipo6')
        ->postJson('/api/v1/servicios/asignaciones', benefitPayload($this->student, ['clave_idempotencia' => 'beca:sol-9']))
        ->assertCreated()
        ->assertJsonPath('data.clave_idempotencia', 'beca:sol-9');
});

test('when only one locker is left the second beneficiary gets an explicit rejection', function () {
    createLocker('LKR-A-001');
    $other = User::factory()->create();

    postBenefit(benefitPayload($this->student), 'beca:a')->assertCreated();

    postBenefit(benefitPayload($other), 'beca:b')
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'sin_disponibilidad');

    expect(BenefitAssignment::count())->toBe(1);

    createLocker('LKR-A-002');

    postBenefit(benefitPayload($other), 'beca:b')
        ->assertCreated()
        ->assertJsonPath('data.recurso.codigo', 'LKR-A-002');
});

test('a locker that another request takes first is skipped instead of double assigned', function () {
    $first = createLocker('LKR-A-001');
    createLocker('LKR-A-002');

    // Otro proceso apartó el primer locker justo antes del apartado atómico.
    Locker::retrieved(function (Locker $locker) use ($first) {
        if ((string) $locker->id === (string) $first->id && $locker->status === 'available') {
            Locker::query()->where('_id', $first->id)->update(['status' => 'occupied']);
        }
    });

    postBenefit(benefitPayload($this->student), 'beca:a')
        ->assertCreated()
        ->assertJsonPath('data.recurso.codigo', 'LKR-A-002');

    expect(LockerAssignment::count())->toBe(1);
});

test('services not managed by team 5 and invalid beneficiaries are rejected', function () {
    postBenefit(benefitPayload($this->student, ['tipo' => 'comidas']), 'beca:x')
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'beneficio_no_soportado');

    postBenefit(benefitPayload($this->student, ['beneficiario_id' => '65f000000000000000000001']), 'beca:y')
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'beneficiario_no_encontrado');

    postBenefit(benefitPayload($this->student, ['vigencia_inicio' => null]), 'beca:z')
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'validacion');
});

test('a student who already has a locker in the period is not eligible', function () {
    createLocker('LKR-A-001');
    createLocker('LKR-A-002');

    postBenefit(benefitPayload($this->student), 'beca:a')->assertCreated();

    postBenefit(benefitPayload($this->student, ['solicitud_id' => 'otra-solicitud']), 'beca:b')
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'beneficiario_con_locker');
});

test('the assignment becomes delivered after the first locker access in 5.11', function () {
    createLocker('LKR-A-001');

    $id = postBenefit(benefitPayload($this->student), 'beca:a')->json('data.asignacion_id');

    ServiceCheckin::create([
        'folio' => 'EVT-1',
        'student_id' => (string) $this->student->id,
        'service' => 'locker',
        'action' => 'entry',
        'reference' => 'LKR-A-001',
        'granted' => true,
        'scanned_at' => now()->addHour(),
    ]);

    $this->withToken('token-equipo6')
        ->getJson('/api/v1/servicios/asignaciones?clave_idempotencia=beca:a')
        ->assertOk()
        ->assertJsonPath('data.0.asignacion_id', $id)
        ->assertJsonPath('data.0.estado', 'entregado')
        ->assertJsonPath('data.0.cantidad.consumida', 1);
});

test('cancelling a locker scholarship releases the locker and is idempotent', function () {
    createLocker('LKR-A-001');

    $id = postBenefit(benefitPayload($this->student), 'beca:a')->json('data.asignacion_id');

    $cancel = fn (string $key) => $this->withHeaders(['Authorization' => 'Bearer token-equipo6', 'Idempotency-Key' => $key])
        ->postJson("/api/v1/servicios/asignaciones/{$id}/cancelacion", ['motivo' => 'Baja del programa', 'referencia_origen' => 'DICT-7']);

    $first = $cancel('cancel:a')
        ->assertOk()
        ->assertJsonPath('data.estado', 'cancelado')
        ->assertJsonPath('data.liberado', true);

    $cancel('cancel:a')
        ->assertOk()
        ->assertJsonPath('data.cancelacion_id', $first->json('data.cancelacion_id'))
        ->assertJsonPath('meta.repetida', true);

    $cancel('cancel:otra')->assertStatus(409)->assertJsonPath('codigo', 'ya_cancelada');

    expect(Locker::first()->status)->toBe('available')
        ->and(LockerAssignment::first()->status)->toBe('released');
});

test('print scholarships are consumed by paying print orders and cancellation frees only the rest', function () {
    $id = postBenefit(benefitPayload($this->student, ['tipo' => 'impresiones', 'cantidad' => 100]), 'beca:imp')
        ->assertCreated()
        ->assertJsonPath('data.estado', 'asignado')
        ->assertJsonPath('data.cantidad.disponible', 100)
        ->json('data.asignacion_id');

    $order = ServiceOrder::create([
        'folio' => 'SER-2026-AAAA0001',
        'student_id' => (string) $this->student->id,
        'service_type' => 'printing',
        'quantity' => 30,
        'quoted_amount_cents' => 6000,
        'payment_status' => 'pending',
        'status' => 'awaiting_payment',
    ]);

    $this->actingAs($this->student)
        ->patch("/servicios-estudiante/servicios-impresiones/{$order->id}/pagar-con-beca")
        ->assertSessionHasNoErrors();

    expect($order->fresh()->payment_status)->toBe('paid')
        ->and($order->fresh()->payment_reference_id)->toStartWith('BECA-BEN-');

    $this->withToken('token-equipo6')
        ->getJson("/api/v1/servicios/asignaciones/{$id}")
        ->assertJsonPath('data.estado', 'en_uso')
        ->assertJsonPath('data.cantidad.consumida', 30)
        ->assertJsonPath('data.evidencia.consumos.0.folio_orden', 'SER-2026-AAAA0001');

    $this->withHeaders(['Authorization' => 'Bearer token-equipo6', 'Idempotency-Key' => 'cancel:imp'])
        ->postJson("/api/v1/servicios/asignaciones/{$id}/cancelacion", ['motivo' => 'Fin de la beca'])
        ->assertOk()
        ->assertJsonPath('data.cantidad_liberada', 70)
        ->assertJsonPath('data.asignacion.cantidad.consumida', 30);

    $second = ServiceOrder::create([
        'folio' => 'SER-2026-AAAA0002',
        'student_id' => (string) $this->student->id,
        'service_type' => 'printing',
        'quantity' => 5,
        'payment_status' => 'pending',
        'status' => 'awaiting_payment',
    ]);

    $this->actingAs($this->student)
        ->patch("/servicios-estudiante/servicios-impresiones/{$second->id}/pagar-con-beca")
        ->assertSessionHasErrors('order');
});

test('each client only sees its own assignments', function () {
    createLocker('LKR-A-001');

    $id = postBenefit(benefitPayload($this->student), 'beca:a')->json('data.asignacion_id');

    $this->withToken('token-otro')
        ->getJson("/api/v1/servicios/asignaciones/{$id}")
        ->assertNotFound()
        ->assertJsonPath('codigo', 'no_encontrada');

    $this->withToken('token-otro')
        ->getJson('/api/v1/servicios/asignaciones?clave_idempotencia=beca:a')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
