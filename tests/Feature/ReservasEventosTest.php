<?php

namespace Tests\Feature;

use App\Models\AuditoriaComunidad;
use App\Models\Campana;
use App\Models\Evento;
use App\Models\PagoReservaEvento;
use App\Models\RegistroEvento;
use App\Models\User;
use App\Services\AudienciaCampana;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class ReservasEventosTest extends TestCase
{
    use RefreshMongoDatabase;

    private User $presidencia;

    private User $alumno;

    private User $andrea;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute());
        $this->seed();
        $this->presidencia = User::where('email', 'presidencia@campus.test')->firstOrFail();
        $this->alumno = User::where('email', 'estudiante@campus.test')->firstOrFail();
        $this->andrea = User::where('email', 'andrea@campus.test')->firstOrFail();
    }

    private function evento(array $extra = []): Evento
    {
        $local = fn ($date) => $date->timezone('America/Mexico_City')->format('Y-m-d\TH:i');
        $id = $this->actingAs($this->presidencia)->postJson('/api/eventos', [...[
            'titulo' => 'Evento con reserva temporal', 'descripcion' => 'Comprobación de reservas e historial.', 'ubicacion' => 'Auditorio',
            'fecha_hora_inicio' => $local(now()->addHours(2)), 'fecha_hora_fin' => $local(now()->addHours(3)),
            'fecha_inicio_registro' => $local(now()->subHour()), 'fecha_fin_registro' => $local(now()->addHour()),
            'costo' => 25, 'capacidad' => 1, 'lista_espera' => true,
        ], ...$extra])->assertCreated()->json('evento.id');
        $this->postJson('/api/eventos/'.$id.'/publicar')->assertOk();

        return Evento::findOrFail($id);
    }

    private function reservar(Evento $e, User $u, string $estado = 'confirmada'): RegistroEvento
    {
        $this->actingAs($u)->postJson('/api/eventos/'.$e->id.'/inscripcion')->assertOk()->assertJsonPath('registro.estado', $estado);

        return RegistroEvento::where('evento_id', (string) $e->id)->where('usuario_id', (string) $u->id)->firstOrFail();
    }

    public function test_pending_reservation_has_fifteen_minutes_and_retry_does_not_extend_it(): void
    {
        $e = $this->evento();
        $r = $this->reservar($e, $this->alumno);
        $this->assertNotNull($r->reserva_hasta);
        $this->assertTrue($r->reserva_hasta->equalTo(now()->addMinutes(15)));
        $this->travel(10)->minutes();
        $this->reservar($e, $this->alumno);
        $this->assertTrue($r->fresh()->reserva_hasta->equalTo($r->reserva_hasta));
        $this->getJson('/api/eventos/'.$e->id.'/boleto')->assertJsonPath('token_qr', null)->assertJsonPath('qr_habilitado', false);
    }

    public function test_deadline_is_exclusive_and_expiration_promotes_fifo_once(): void
    {
        $e = $this->evento();
        $r = $this->reservar($e, $this->alumno);
        $wait = $this->reservar($e, $this->andrea, 'espera');
        $this->assertNull($wait->reserva_hasta);
        $this->travel(15)->minutes();
        $this->actingAs($this->alumno)->getJson('/api/eventos/'.$e->id.'/boleto')->assertJsonPath('estado', 'vencida')->assertJsonPath('puede_cancelar', false);
        $this->artisan('comunidad:expirar-reservas-eventos')->assertExitCode(0);
        $this->assertSame('vencida', $r->fresh()->estado);
        $this->assertNotSame($r->token_qr, $r->fresh()->token_qr);
        $this->assertSame('confirmada', $wait->fresh()->estado);
        $this->assertTrue($wait->fresh()->reserva_hasta->equalTo(now()->addMinutes(15)));
        $this->artisan('comunidad:expirar-reservas-eventos')->assertExitCode(0);
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'evento_reserva_vencida')->count());
        $this->assertStringNotContainsString($r->token_qr, AuditoriaComunidad::all()->toJson());
    }

    public function test_free_and_paid_tickets_do_not_expire(): void
    {
        $free = $this->evento(['costo' => 0]);
        $f = $this->reservar($free, $this->alumno);
        $paid = $this->evento();
        $p = $this->reservar($paid, $this->alumno);
        // Fixture de una confirmación futura confiable; la aplicación no ofrece confirmar pagos.
        $p->update(['estado_pago' => 'pagado']);
        $this->travel(16)->minutes();
        $this->artisan('comunidad:expirar-reservas-eventos')->assertExitCode(0);
        $this->assertSame('confirmada', $f->fresh()->estado);
        $this->assertSame('confirmada', $p->fresh()->estado);
        $this->assertSame(0, AuditoriaComunidad::where('accion', 'evento_reserva_vencida')->count());
    }

    public function test_cancelling_a_paid_reservation_requires_financial_resolution_and_preserves_the_queue(): void
    {
        $evento = $this->evento();
        $titular = $this->reservar($evento, $this->alumno);
        $espera = $this->reservar($evento, $this->andrea, 'espera');

        // Model the E6 state after an accepted receipt without calling a financial API.
        $titular->update(['estado_pago' => 'pagado']);
        $this->actingAs($this->alumno)->deleteJson('/api/eventos/'.$evento->id.'/inscripcion')
            ->assertUnprocessable()->assertJsonValidationErrors('evento');

        $this->travel(15)->minutes();
        $this->artisan('comunidad:expirar-reservas-eventos')->assertExitCode(0);
        $this->getJson('/api/eventos/'.$evento->id.'/boleto')
            ->assertJsonPath('estado', 'confirmada')->assertJsonPath('qr_habilitado', true);
        $this->actingAs($this->andrea)->getJson('/api/eventos/'.$evento->id.'/boleto')
            ->assertJsonPath('estado', 'espera')->assertJsonPath('qr_habilitado', false);
        $this->assertNull($espera->fresh()->reserva_hasta);
    }

    public function test_promoted_waiter_gets_a_deadline_capped_at_event_start_after_registration_closes(): void
    {
        $local = fn ($d) => $d->timezone('America/Mexico_City')->format('Y-m-d\TH:i');
        $e = $this->evento(['fecha_hora_inicio' => $local(now()->addMinutes(20)), 'fecha_fin_registro' => $local(now()->addMinutes(5))]);
        $this->reservar($e, $this->alumno);
        $w = $this->reservar($e, $this->andrea, 'espera');
        $this->travel(15)->minutes();
        $this->artisan('comunidad:expirar-reservas-eventos')->assertExitCode(0);
        $this->assertSame('confirmada', $w->fresh()->estado);
        $this->assertTrue($w->fresh()->reserva_hasta->equalTo($e->fecha_hora_inicio));
    }

    public function test_legacy_pending_booking_gets_one_grace_period(): void
    {
        $e = $this->evento();
        $r = RegistroEvento::create(['evento_id' => (string) $e->id, 'usuario_id' => (string) $this->alumno->id, 'estado' => 'confirmada', 'estado_pago' => 'pendiente', 'estado_asistencia' => 'pendiente', 'registrado_en' => now()->subDay()]);
        $this->artisan('comunidad:expirar-reservas-eventos')->assertExitCode(0);
        $this->assertTrue($r->fresh()->reserva_hasta->equalTo(now()->addMinutes(15)));
        $this->travel(10)->minutes();
        $this->artisan('comunidad:expirar-reservas-eventos')->assertExitCode(0);
        $this->assertTrue($r->fresh()->reserva_hasta->equalTo(now()->addMinutes(5)));
        $this->travel(5)->minutes();
        $this->artisan('comunidad:expirar-reservas-eventos')->assertExitCode(0);
        $this->assertSame('vencida', $r->fresh()->estado);
    }

    public function test_read_models_exclude_overdue_reservations_before_the_scheduler_runs(): void
    {
        $e = $this->evento();
        $this->reservar($e, $this->alumno);
        $this->travel(15)->minutes();
        $this->getJson('/api/eventos')->assertJsonPath('eventos.0.stats.confirmados', 0);
        $this->getJson('/api/dashboard')->assertJsonPath('personales.boletos', 0)->assertJsonPath('proximoEvento.reservas', 0);
        $this->actingAs($this->presidencia)->getJson('/api/eventos/'.$e->id.'/inscritos?estado=confirmada')->assertJsonCount(0, 'registros');
        $this->getJson('/api/eventos/'.$e->id.'/inscritos?estado=vencida')->assertJsonCount(1, 'registros')->assertJsonPath('registros.0.estado', 'vencida');
        $campana = new Campana(['organizacion_id' => $e->organizacion_id, 'audiencia' => 'evento_confirmados', 'referencia_id' => (string) $e->id]);
        $this->assertSame([], app(AudienciaCampana::class)->ids($campana));
    }

    public function test_event_payment_applies_only_matching_evidence_and_recovers_idempotently(): void
    {
        $evento = $this->evento();
        $reg = $this->reservar($evento, $this->alumno);
        $url = '/api/eventos/'.$evento->id.'/pago';
        $finanzas = $this->finanzas($reg);
        $this->postJson($url, ['generacion' => 1, 'amount_cents' => 1])->assertUnprocessable();
        $this->postJson($url, ['generacion' => 1])->assertOk()->assertJsonPath('confirmation_path', $finanzas->path);
        $this->postJson($url, ['generacion' => 1])->assertOk()->assertJsonPath('confirmation_path', $finanzas->path);
        $this->assertSame(1, $finanzas->prepared);

        $finanzas->status = 'CONFIRMED';
        $finanzas->importe = 2400;
        $this->postJson($url.'/consultar', ['generacion' => 1])->assertServiceUnavailable();
        $this->getJson('/api/eventos/'.$evento->id.'/boleto')->assertJsonPath('qr_habilitado', false);
        $finanzas->importe = 2500;
        $this->artisan('comunidad:conciliar-pagos-eventos')->assertSuccessful();
        $this->postJson($url.'/consultar', ['generacion' => 1])->assertOk()->assertJsonPath('estado', 'aplicado');
        $this->getJson('/api/eventos/'.$evento->id.'/boleto')->assertJsonPath('qr_habilitado', true);
        $this->assertSame($finanzas->payment, PagoReservaEvento::firstOrFail()->comprobante);
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'evento_pago_aplicado')->count());
    }

    public function test_old_confirmed_payment_enters_refund_review_without_unlocking_the_new_generation(): void
    {
        $evento = $this->evento();
        $reg = $this->reservar($evento, $this->alumno);
        $url = '/api/eventos/'.$evento->id;
        $finanzas = $this->finanzas($reg);
        $this->postJson($url.'/pago', ['generacion' => 1])->assertOk();
        $this->travel(15)->minutes();
        $this->artisan('comunidad:expirar-reservas-eventos')->assertSuccessful();
        $this->postJson($url.'/inscripcion', ['generacion' => 1, 'clave_solicitud' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('registro.generacion', 2);
        $finanzas->status = 'CONFIRMED';
        $this->postJson($url.'/pago/consultar', ['generacion' => 1])->assertOk()->assertJsonPath('estado', 'devolucion_pendiente');
        $this->getJson($url.'/boleto')->assertJsonPath('generacion', 2)->assertJsonPath('qr_habilitado', false)
            ->assertJsonPath('estado_pago', 'pendiente');
        $this->assertSame($finanzas->payment, PagoReservaEvento::firstOrFail()->comprobante);
        $finanzas->refund = ['refund_id' => (string) Str::uuid(), 'original_payment_id' => $finanzas->payment,
            'amount_cents' => 2500, 'currency' => 'MXN'];
        $this->artisan('comunidad:conciliar-pagos-eventos')->assertSuccessful();
        $this->postJson($url.'/pago/consultar', ['generacion' => 1])->assertOk()->assertJsonPath('estado', 'devuelto');
        $this->getJson($url.'/boleto')->assertJsonPath('generacion', 2)->assertJsonPath('qr_habilitado', false)
            ->assertJsonPath('estado_pago', 'pendiente');
        $this->assertSame($finanzas->refund['refund_id'], PagoReservaEvento::firstOrFail()->comprobante_devolucion);
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'evento_pago_devuelto')->count());
    }

    public function test_finance_must_close_the_payment_before_cancellation_releases_an_active_seat(): void
    {
        $evento = $this->evento();
        $reg = $this->reservar($evento, $this->alumno);
        $espera = $this->reservar($evento, $this->andrea, 'espera');
        $url = '/api/eventos/'.$evento->id;
        $finanzas = $this->finanzas($reg);
        $this->actingAs($this->alumno)->postJson($url.'/pago', ['generacion' => 1])->assertOk();
        $this->deleteJson($url.'/inscripcion')->assertOk()->assertJsonPath('registro.pago_estado', 'cancelacion_pendiente');
        $this->assertSame('espera', $espera->fresh()->estado);
        $finanzas->status = 'CANCELLED';
        $this->artisan('comunidad:conciliar-pagos-eventos')->assertSuccessful();
        $this->assertSame('cancelada', $reg->fresh()->estado);
        $this->assertSame('confirmada', $espera->fresh()->estado);
    }

    private function finanzas(RegistroEvento $reg): object
    {
        config(['comunidad.eventos_pagos' => ['enabled' => true, 'url' => 'http://finanzas.test',
            'client_id' => 'comunidad-fixture', 'client_secret' => 'fixture-secret']]);
        $id = (string) Str::uuid();
        $fixture = (object) ['prepared' => 0, 'status' => 'PENDING', 'importe' => $reg->monto_centavos,
            'path' => '/finanzas/eventos/solicitudes/'.$id, 'payment' => (string) Str::uuid(),
            'confirmado' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'), 'refund' => null];
        Http::preventStrayRequests();
        Http::fake([
            'http://finanzas.test/api/oauth/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 120]),
            'http://finanzas.test/api/v1/financial/community/*' => function ($request) use ($reg, $id, $fixture) {
                $ref = 'event-reservation:'.$reg->id.':1';
                if ($request->method() === 'GET' && $fixture->prepared === 0) {
                    return Http::response([], 404);
                }
                if ($request->method() === 'POST' && ! str_ends_with($request->url(), '/cancel')) {
                    $fixture->prepared++;
                    $this->assertSame([$ref], $request->header('Idempotency-Key'));
                    $this->assertSame($reg->monto_centavos, $request['amount_cents']);
                    $this->assertSame(1, PagoReservaEvento::count());
                }

                return Http::response(['data' => [
                    'source_reference' => $ref, 'request_id' => $id, 'status' => $fixture->status,
                    'student_id' => $reg->usuario_id, 'event_id' => $reg->evento_id, 'organization_id' => (string) Evento::find($reg->evento_id)->organizacion_id,
                    'amount_cents' => $fixture->importe, 'currency' => 'MXN',
                    'reservation_expires_at' => $reg->reserva_hasta->copy()->utc()->format('Y-m-d\TH:i:s.v\Z'),
                    'refund' => $fixture->refund, 'payment_id' => $fixture->status === 'CONFIRMED' ? $fixture->payment : null,
                    'confirmed_at' => $fixture->status === 'CONFIRMED' ? $fixture->confirmado : null,
                    'confirmation_path' => $fixture->path,
                ]]);
            },
        ]);

        return $fixture;
    }

    private function pagoAplicado(): array
    {
        $evento = $this->evento();
        $reg = $this->reservar($evento, $this->alumno);
        $espera = $this->reservar($evento, $this->andrea, 'espera');
        $url = '/api/eventos/'.$evento->id;
        $finanzas = $this->finanzas($reg);
        $this->actingAs($this->alumno)->postJson($url.'/pago', ['generacion' => 1])->assertOk();
        $finanzas->status = 'CONFIRMED';
        $this->artisan('comunidad:conciliar-pagos-eventos')->assertSuccessful();
        $this->assertSame('aplicado', PagoReservaEvento::firstOrFail()->estado);

        return [$evento, $reg, $espera, $url, $finanzas];
    }

    public function test_finance_refund_of_an_applied_payment_revokes_the_seat_and_promotes_the_queue(): void
    {
        [$evento, $reg, $espera, $url, $finanzas] = $this->pagoAplicado();
        $qr = $reg->fresh()->token_qr;
        $finanzas->refund = ['refund_id' => (string) Str::uuid(), 'original_payment_id' => $finanzas->payment,
            'amount_cents' => 2500, 'currency' => 'MXN'];
        $this->artisan('comunidad:conciliar-pagos-eventos')->assertSuccessful();
        $pago = PagoReservaEvento::firstOrFail();
        $this->assertSame('devuelto', $pago->estado);
        $this->assertSame($finanzas->refund['refund_id'], $pago->comprobante_devolucion);
        $this->assertSame('cancelada', $reg->fresh()->estado);
        $this->assertSame('devuelto', $reg->fresh()->pago_estado);
        $this->assertNotSame($qr, $reg->fresh()->token_qr);
        $this->assertSame('confirmada', $espera->fresh()->estado);
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'evento_pago_devuelto_externo')->count());
        $this->artisan('comunidad:conciliar-pagos-eventos')->assertSuccessful();
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'evento_pago_devuelto_externo')->count());
    }

    public function test_applied_seat_is_kept_without_matching_refund_evidence(): void
    {
        [$evento, $reg, $espera, $url, $finanzas] = $this->pagoAplicado();
        $this->artisan('comunidad:conciliar-pagos-eventos')->assertSuccessful();
        $finanzas->refund = ['refund_id' => (string) Str::uuid(), 'original_payment_id' => $finanzas->payment,
            'amount_cents' => 2400, 'currency' => 'MXN'];
        $this->artisan('comunidad:conciliar-pagos-eventos')->assertSuccessful();
        $finanzas->refund = ['refund_id' => (string) Str::uuid(), 'original_payment_id' => (string) Str::uuid(),
            'amount_cents' => 2500, 'currency' => 'MXN'];
        $this->artisan('comunidad:conciliar-pagos-eventos')->assertSuccessful();
        $this->assertSame('aplicado', PagoReservaEvento::firstOrFail()->estado);
        $this->assertSame('confirmada', $reg->fresh()->estado);
        $this->assertSame('espera', $espera->fresh()->estado);
        $this->actingAs($this->alumno)->postJson($url.'/pago/consultar', ['generacion' => 1])->assertOk()->assertJsonPath('estado', 'aplicado');
        $this->assertSame(0, AuditoriaComunidad::where('accion', 'like', 'evento_pago_devuelto%')->count());
    }

    public function test_refund_after_attendance_is_recorded_for_review_without_rewriting_attendance(): void
    {
        [$evento, $reg, $espera, $url, $finanzas] = $this->pagoAplicado();
        $reg->fresh()->update(['estado_asistencia' => 'asistio', 'asistio_en' => now()]);
        $finanzas->refund = ['refund_id' => (string) Str::uuid(), 'original_payment_id' => $finanzas->payment,
            'amount_cents' => 2500, 'currency' => 'MXN'];
        $this->artisan('comunidad:conciliar-pagos-eventos')->assertSuccessful();
        $this->assertSame('devuelto', PagoReservaEvento::firstOrFail()->estado);
        $this->assertSame('confirmada', $reg->fresh()->estado);
        $this->assertSame('asistio', $reg->fresh()->estado_asistencia);
        $this->assertSame('espera', $espera->fresh()->estado);
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'evento_pago_devuelto_con_asistencia')->count());
    }

    public function test_audit_failure_rolls_back_the_booking(): void
    {
        $e = $this->evento();
        $fallar = true;
        AuditoriaComunidad::creating(function ($a) use (&$fallar) {
            if ($fallar && $a->accion === 'evento_inscripcion') {
                $fallar = false;
                throw new \RuntimeException('Fallo controlado de auditoría de reserva');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->alumno)->postJson('/api/eventos/'.$e->id.'/inscripcion');
            $this->fail('Debe fallar la auditoría.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fallo controlado de auditoría de reserva', $e->getMessage());
        }
        $this->assertSame(0, RegistroEvento::count());
    }
}
