<?php

use App\Models\AuditoriaComunidad;
use App\Models\Evento;
use App\Models\Organizacion;
use App\Models\RegistroEvento;
use App\Models\StaffEvento;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;

beforeEach(function () {
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $this->seed();
    $this->president = User::where('email', 'presidencia@campus.test')->firstOrFail();
    $this->staff = User::where('email', 'estudiante@campus.test')->firstOrFail();
    $this->org = Organizacion::where('slug', 'sistemas')->firstOrFail();
    $this->event = Evento::create(['organizacion_id' => (string) $this->org->id, 'titulo' => 'Acceso NFC',
        'estado' => 'publicado', 'capacidad' => 5, 'fecha_hora_inicio' => now()->addMinutes(20), 'fecha_hora_fin' => now()->addHours(2)]);
    $this->assignment = StaffEvento::create(['evento_id' => (string) $this->event->id, 'organizacion_id' => (string) $this->org->id,
        'usuario_id' => (string) $this->staff->id, 'asignado_por' => (string) $this->president->id]);
    $this->remoteId = (string) new ObjectId;
    $this->registration = RegistroEvento::create(['evento_id' => (string) $this->event->id, 'usuario_id' => $this->remoteId,
        'estado' => 'confirmada', 'estado_pago' => 'exento', 'estado_asistencia' => 'pendiente',
        'token_qr' => (string) Str::uuid(), 'registrado_en' => now()]);
    $this->identityReply = ['ok' => true, 'result' => 'valid', 'credential' => ['status' => 'active'], 'student_id' => $this->remoteId,
        'identity' => ['user_id' => $this->remoteId, 'name' => 'Persona validada por E1', 'student' => ['enrollment_number' => 'E1-API-TEST']]];
    $this->identityStatus = 200;
    $this->revokeDuringLookup = false;
    config(['comunidad.eventos_nfc_habilitado' => true, 'identidad_comunidad' => ['url' => 'http://identity.test', 'client_id' => 'comunidad-test', 'client_secret' => 'only-test']]);
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/api/oauth/token')) {
            expect($request['scope'])->toBe('identity:nfc:validate');

            return Http::response(['access_token' => 'test-only-nfc-token', 'expires_in' => 300]);
        }
        if (str_ends_with($request->url(), '/api/v1/identity/nfc-validate')) {
            expect($request['credential_uid'])->toBe('TEST-NFC-001');
            if ($this->revokeDuringLookup) {
                $this->assignment->update(['eliminado_en' => now()]);
            }

            return Http::response($this->identityReply, $this->identityStatus);
        }
        throw new RuntimeException('Consulta externa no prevista.');
    });
});

function staffNfcScan($test, array $changes = [])
{
    return $test->postJson('/api/eventos/checkin', array_replace(['evento_id' => (string) $test->event->id,
        'metodo' => 'nfc', 'credential_uid' => 'TEST-NFC-001'], $changes));
}

it('resolves the canonical identity by API and admits once without a local identity lookup', function () {
    $this->actingAs($this->staff);
    $response = staffNfcScan($this)->assertOk()->assertJsonPath('asistente.name', 'Persona validada por E1')->assertJsonPath('asistente.matricula', 'E1-API-TEST');
    $response->assertDontSee('TEST-NFC-001')->assertDontSee($this->registration->token_qr);
    staffNfcScan($this)->assertUnprocessable();
    expect($this->registration->fresh()->estado_asistencia)->toBe('asistio')
        ->and($this->registration->fresh()->asistencia_por)->toBe((string) $this->staff->id);
    $audit = AuditoriaComunidad::where('accion', 'evento_asistencia')->sole();
    expect($audit->despues['metodo_acceso'])->toBe('nfc')
        ->and(json_encode($audit->toArray()))->not->toContain('TEST-NFC-001');
});

it('authorizes staff event and time before contacting Identity', function () {
    staffNfcScan($this)->assertUnauthorized();
    $this->actingAs(User::where('email', 'andrea@campus.test')->firstOrFail());
    staffNfcScan($this)->assertForbidden();
    $this->actingAs($this->staff);
    $this->event->update(['fecha_hora_inicio' => now()->addHours(3), 'fecha_hora_fin' => now()->addHours(5)]);
    staffNfcScan($this)->assertUnprocessable();
    Http::assertNothingSent();
});

it('rechecks a revoked staff assignment after the identity response', function () {
    $this->revokeDuringLookup = true;
    $this->actingAs($this->staff);
    staffNfcScan($this)->assertForbidden();
    expect($this->registration->fresh()->estado_asistencia)->toBe('pendiente');
});

it('does not use identity as a substitute for enrollment payment or an unused ticket', function () {
    $this->actingAs($this->staff);
    foreach ([['estado' => 'espera'], ['estado' => 'cancelada'], ['estado' => 'vencida'], ['estado_pago' => 'pendiente']] as $change) {
        $this->registration->update(array_replace(['estado' => 'confirmada', 'estado_pago' => 'exento'], $change));
        staffNfcScan($this)->assertUnprocessable()->assertDontSee('Persona validada por E1');
        expect($this->registration->fresh()->estado_asistencia)->toBe('pendiente');
    }
    $this->registration->update(['usuario_id' => (string) new ObjectId, 'estado' => 'confirmada', 'estado_pago' => 'exento']);
    staffNfcScan($this)->assertUnprocessable();
});

it('rejects revoked credentials and malformed success responses without exposing identity', function () {
    $this->actingAs($this->staff);
    $good = $this->identityReply;
    $this->identityStatus = 422;
    $this->identityReply = ['ok' => false, 'result' => 'revoked'];
    staffNfcScan($this)->assertUnprocessable();
    $this->identityStatus = 200;
    foreach ([[], array_replace($good, ['student_id' => 'different-id']), array_replace($good, ['credential' => ['status' => 'revoked']]),
        array_replace($good, ['identity' => ['user_id' => $this->remoteId, 'name' => ['invalid']]])] as $reply) {
        $this->identityReply = $reply;
        staffNfcScan($this)->assertStatus(503)->assertDontSee('TEST-NFC-001');
    }
    expect($this->registration->fresh()->estado_asistencia)->toBe('pendiente');
});

it('fails closed on missing scope server errors and connection failures', function () {
    $this->actingAs($this->staff);
    foreach ([400, 401, 403, 500] as $status) {
        $this->identityStatus = $status;
        staffNfcScan($this)->assertStatus(503);
    }
    Http::fake(fn () => throw new ConnectionException('test-only disconnect'));
    staffNfcScan($this)->assertStatus(503);
    expect($this->registration->fresh()->estado_asistencia)->toBe('pendiente');
});

it('keeps NFC disabled until activated and reports availability in Staff', function () {
    config(['comunidad.eventos_nfc_habilitado' => false]);
    $this->actingAs($this->staff);
    staffNfcScan($this)->assertStatus(503);
    $this->getJson('/api/staff/eventos')->assertOk()->assertJsonPath('nfc_habilitado', false);
    Http::assertNothingSent();
});

it('does not accept two credential types or a subject supplied by the browser', function () {
    $this->actingAs($this->staff);
    staffNfcScan($this, ['token_qr' => 'another-ticket'])->assertUnprocessable();
    staffNfcScan($this, ['usuario_id' => $this->remoteId])->assertUnprocessable();
    Http::assertNothingSent();
});

it('rolls back NFC attendance if its audit cannot be stored', function () {
    $this->actingAs($this->staff);
    AuditoriaComunidad::creating(fn () => throw new RuntimeException('test-only audit failure'));
    try {
        staffNfcScan($this)->assertStatus(500)->assertDontSee('TEST-NFC-001');
        expect($this->registration->fresh()->estado_asistencia)->toBe('pendiente')
            ->and(AuditoriaComunidad::where('accion', 'evento_asistencia')->count())->toBe(0);
    } finally {
        AuditoriaComunidad::flushEventListeners();
    }
});
