<?php

namespace Tests\Feature;

use App\Models\AuditoriaComunidad;
use App\Models\Evento;
use App\Models\RegistroEvento;
use App\Models\SolicitudReservaEvento;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class IntentosReservasEventosTest extends TestCase
{
    use RefreshMongoDatabase;

    private User $alumno;

    private User $presidencia;

    private User $andrea;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute());
        $this->seed();
        $this->alumno = User::where('email', 'estudiante@campus.test')->firstOrFail();
        $this->presidencia = User::where('email', 'presidencia@campus.test')->firstOrFail();
        $this->andrea = User::where('email', 'andrea@campus.test')->firstOrFail();
    }

    private function evento(): Evento
    {
        $local = fn ($d) => $d->timezone('America/Mexico_City')->format('Y-m-d\TH:i');
        $id = $this->actingAs($this->presidencia)->postJson('/api/eventos', ['titulo' => 'Evento para reintentos', 'descripcion' => 'Inscripciones con una intención explícita.', 'ubicacion' => 'Auditorio', 'fecha_hora_inicio' => $local(now()->addHours(2)), 'fecha_hora_fin' => $local(now()->addHours(3)), 'fecha_inicio_registro' => $local(now()->subHour()), 'fecha_fin_registro' => $local(now()->addHour()), 'costo' => 25, 'capacidad' => 1, 'lista_espera' => true])->assertCreated()->json('evento.id');
        $this->postJson('/api/eventos/'.$id.'/publicar')->assertOk();

        return Evento::findOrFail($id);
    }

    private function datos(int $generacion, ?string $clave = null): array
    {
        return ['generacion' => $generacion, 'clave_solicitud' => $clave ?? (string) Str::uuid()];
    }

    private function registro(Evento $e): RegistroEvento
    {
        return RegistroEvento::where('evento_id', (string) $e->id)->where('usuario_id', (string) $this->alumno->id)->firstOrFail();
    }

    public function test_retries_preserve_deadline_and_expired_intent_cannot_reopen_a_booking(): void
    {
        $e = $this->evento();
        $url = '/api/eventos/'.$e->id.'/inscripcion';
        $d = $this->datos(0);
        $this->actingAs($this->alumno)->postJson($url, $d)->assertOk()->assertJsonPath('registro.generacion', 1)->assertJsonPath('repetida', false);
        $r = $this->registro($e);
        $limite = $r->reserva_hasta;
        $this->travel(10)->minutes();
        $this->postJson($url, $d)->assertOk()->assertJsonPath('repetida', true);
        $this->assertTrue($r->fresh()->reserva_hasta->equalTo($limite));
        $this->travel(5)->minutes();
        $this->postJson($url, $d)->assertOk()->assertJsonPath('registro.estado', 'vencida');
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'evento_inscripcion')->count());
        $this->postJson($url, $this->datos(1))->assertOk()->assertJsonPath('registro.generacion', 2)->assertJsonPath('registro.estado', 'confirmada');
        $this->postJson($url, $d)->assertOk()->assertJsonPath('generacion_solicitud', 1)->assertJsonPath('registro.generacion', 2);
        $this->assertSame(1, RegistroEvento::count());
        $this->assertSame(2, SolicitudReservaEvento::count());
    }

    public function test_old_cancellation_retry_cannot_cancel_a_new_generation(): void
    {
        $e = $this->evento();
        $url = '/api/eventos/'.$e->id.'/inscripcion';
        $this->actingAs($this->alumno)->postJson($url, $this->datos(0))->assertOk();
        $cancelar = $this->datos(1);
        $this->deleteJson($url, $cancelar)->assertOk()->assertJsonPath('registro.estado', 'cancelada');
        $this->postJson($url, $this->datos(1))->assertOk()->assertJsonPath('registro.generacion', 2);
        $this->deleteJson($url, $cancelar)->assertOk()->assertJsonPath('repetida', true)->assertJsonPath('registro.estado', 'confirmada');
        $this->deleteJson($url, $this->datos(1))->assertConflict();
        $this->assertSame('confirmada', $this->registro($e)->estado);
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'evento_inscripcion_cancelada')->count());
    }

    public function test_keys_are_bound_to_action_event_and_generation_and_legacy_calls_cannot_change_opted_in_bookings(): void
    {
        $e = $this->evento();
        $otro = $this->evento();
        $url = '/api/eventos/'.$e->id.'/inscripcion';
        $d = $this->datos(0);
        $this->actingAs($this->alumno)->postJson($url, $d)->assertOk();
        $this->postJson($url, [...$d, 'generacion' => 1])->assertConflict();
        $this->postJson('/api/eventos/'.$otro->id.'/inscripcion', $d)->assertConflict();
        $this->deleteJson($url, $d)->assertConflict();
        $this->postJson($url)->assertConflict();
        $this->deleteJson($url)->assertConflict();
        $this->postJson($url, ['generacion' => 1])->assertUnprocessable();
        $this->postJson($url, $this->datos(1, 'invalida'))->assertUnprocessable();
        $this->assertSame(1, SolicitudReservaEvento::count());
    }

    public function test_a_failed_request_receipt_rolls_back_booking_and_audit(): void
    {
        $e = $this->evento();
        $url = '/api/eventos/'.$e->id.'/inscripcion';
        $d = $this->datos(0);
        $fallar = true;
        SolicitudReservaEvento::creating(function () use (&$fallar) {
            if ($fallar) {
                $fallar = false;
                throw new \RuntimeException('Fallo controlado del recibo de solicitud');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->alumno)->postJson($url, $d);
            $this->fail('Debe fallar el recibo.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Fallo controlado del recibo de solicitud', $error->getMessage());
        }
        $this->assertSame(0, RegistroEvento::count());
        $this->assertSame(0, SolicitudReservaEvento::count());
        $this->assertSame(0, AuditoriaComunidad::where('accion', 'evento_inscripcion')->count());
        $this->postJson($url, $d)->assertOk()->assertJsonPath('registro.generacion', 1);
    }

    public function test_waiting_retry_after_promotion_preserves_price_deadline_and_generation(): void
    {
        $e = $this->evento();
        $url = '/api/eventos/'.$e->id.'/inscripcion';
        $this->actingAs($this->andrea)->postJson($url, $this->datos(0))->assertOk();
        $d = $this->datos(0);
        $this->actingAs($this->alumno)->postJson($url, [...$d, 'estado_pago' => 'pagado', 'monto_centavos' => 1])->assertOk()->assertJsonPath('registro.estado', 'espera')->assertJsonPath('registro.monto_centavos', 2500);
        $this->travel(15)->minutes();
        $this->artisan('comunidad:expirar-reservas-eventos')->assertExitCode(0);
        $r = $this->registro($e);
        $this->assertTrue($r->reserva_hasta->equalTo(now()->addMinutes(15)));
        $this->travel(1)->minutes();
        $this->postJson($url, $d)->assertOk()->assertJsonPath('registro.generacion', 1)->assertJsonPath('registro.estado_pago', 'pendiente');
        $this->assertTrue($r->fresh()->reserva_hasta->equalTo($r->reserva_hasta));
    }
}
