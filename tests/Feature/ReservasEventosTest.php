<?php

namespace Tests\Feature;

use App\Models\AuditoriaComunidad;
use App\Models\Campana;
use App\Models\Evento;
use App\Models\RegistroEvento;
use App\Models\User;
use App\Services\AudienciaCampana;
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
