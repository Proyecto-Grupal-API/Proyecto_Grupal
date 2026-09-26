<?php

namespace Tests\Feature;

use App\Models\AuditoriaComunidad;
use App\Models\Evento;
use App\Models\Organizacion;
use App\Models\RegistroEvento;
use App\Models\User;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class EventosTest extends TestCase
{
    use RefreshMongoDatabase;

    private User $presidente;

    private User $estudiante;

    private User $andrea;

    private Organizacion $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute());
        $this->seed();
        $this->presidente = User::where('email', 'presidencia@campus.test')->firstOrFail();
        $this->estudiante = User::where('email', 'estudiante@campus.test')->firstOrFail();
        $this->andrea = User::where('email', 'andrea@campus.test')->firstOrFail();
        $this->org = Organizacion::where('slug', 'sistemas')->firstOrFail();
    }

    private function payload(array $overrides = []): array
    {
        $local = fn ($date) => $date->timezone('America/Mexico_City')->format('Y-m-d\TH:i');

        return array_replace([
            'titulo' => 'Taller de sistemas', 'descripcion' => 'Actividad de prueba', 'ubicacion' => 'Aula 6',
            'fecha_hora_inicio' => $local(now()->addMinutes(20)), 'fecha_hora_fin' => $local(now()->addHours(2)),
            'fecha_inicio_registro' => $local(now()->subDay()), 'fecha_fin_registro' => $local(now()->addMinutes(20)),
            'costo' => 0, 'capacidad' => 1, 'lista_espera' => true,
        ], $overrides);
    }

    private function evento(array $overrides = [], bool $publicar = true): Evento
    {
        $id = $this->actingAs($this->presidente)->postJson('/api/eventos', $this->payload($overrides))
            ->assertCreated()->json('evento.id');
        if ($publicar) {
            $this->postJson("/api/eventos/$id/publicar")->assertOk();
        }

        return Evento::findOrFail($id);
    }

    private function inscribir(User $user, Evento $e, string $estado = 'confirmada'): RegistroEvento
    {
        $this->actingAs($user)->postJson("/api/eventos/$e->id/inscripcion")->assertOk()->assertJsonPath('registro.estado', $estado);

        return RegistroEvento::where('evento_id', (string) $e->id)->where('usuario_id', (string) $user->id)->firstOrFail();
    }

    public function test_drafts_are_private_and_publication_is_visible_without_membership(): void
    {
        $e = $this->evento(publicar: false);
        $this->assertTrue($e->fecha_hora_inicio->equalTo(now()->addMinutes(20)));
        $this->actingAs($this->andrea)->getJson('/api/eventos')->assertOk()->assertJsonCount(0, 'eventos');
        $this->postJson("/api/eventos/$e->id/inscripcion")->assertNotFound();
        $this->actingAs($this->presidente)->getJson('/api/eventos?gestion=1')->assertJsonCount(1, 'eventos');
        $this->postJson("/api/eventos/$e->id/publicar")->assertOk();
        $this->postJson("/api/eventos/$e->id/publicar")->assertOk();
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'evento_publicado')->count());
        $this->actingAs($this->andrea)->getJson('/api/eventos')->assertJsonCount(1, 'eventos');
        $this->inscribir($this->andrea, $e);
    }

    public function test_invalid_dates_money_and_capacity_are_rejected(): void
    {
        $this->actingAs($this->presidente)->postJson('/api/eventos', $this->payload([
            'fecha_hora_inicio' => now()->subDay()->format('Y-m-d\TH:i'), 'costo' => 1.234, 'capacidad' => 0,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['costo', 'capacidad']);
        $this->postJson('/api/eventos', $this->payload(['fecha_hora_inicio' => now()->subDay()->format('Y-m-d\TH:i')]))
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_hora_inicio');
        $this->postJson('/api/eventos', $this->payload(['fecha_fin_registro' => now()->addYear()->format('Y-m-d\TH:i')]))
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_fin_registro');
        $this->assertSame(0, Evento::count());
    }

    public function test_permissions_isolate_managers_and_do_not_leak_ticket_tokens(): void
    {
        $e = $this->evento();
        $reg = $this->inscribir($this->estudiante, $e);
        $this->getJson("/api/eventos/$e->id/inscritos")->assertForbidden();
        $this->getJson('/api/eventos?gestion=1')->assertForbidden();
        $this->postJson('/api/eventos', $this->payload())->assertForbidden();
        $this->getJson('/api/eventos')->assertDontSee($reg->token_qr);
        $this->actingAs($this->andrea)->getJson("/api/eventos/$e->id/boleto")->assertNotFound();
        $this->getJson('/api/estudiante/boletos')->assertJsonCount(0, 'boletos');
        $this->deleteJson("/api/eventos/$e->id/inscripcion")->assertNotFound();
        $other = User::where('email', 'consejo@campus.test')->firstOrFail();
        $this->actingAs($other)->putJson("/api/eventos/$e->id", $this->payload())->assertNotFound();
        $this->getJson("/api/eventos/$e->id/inscritos")->assertNotFound();
        $this->postJson('/api/eventos/checkin', ['evento_id' => (string) $e->id, 'token_qr' => $reg->token_qr])->assertNotFound();
        $this->actingAs($this->presidente)->getJson("/api/eventos/$e->id/inscritos")->assertOk()->assertJsonPath('registros.0.matricula', '20260002')->assertDontSee($reg->token_qr);
        $this->assertStringNotContainsString($reg->token_qr, AuditoriaComunidad::all()->toJson());
    }

    public function test_retries_do_not_duplicate_and_waiting_list_promotes_fifo(): void
    {
        $e = $this->evento();
        $first = $this->inscribir($this->estudiante, $e);
        $again = $this->inscribir($this->estudiante, $e);
        $this->assertSame($first->token_qr, $again->token_qr);
        $this->travel(1)->seconds();
        $second = $this->inscribir($this->andrea, $e, 'espera');
        $this->getJson("/api/eventos/$e->id/boleto")->assertJsonPath('posicion_espera', 1)->assertJsonPath('token_qr', null);
        $this->travel(1)->seconds();
        $third = $this->inscribir($this->presidente, $e, 'espera');
        $this->getJson("/api/eventos/$e->id/boleto")->assertJsonPath('posicion_espera', 2);
        $this->actingAs($this->estudiante)->deleteJson("/api/eventos/$e->id/inscripcion")->assertOk();
        $this->deleteJson("/api/eventos/$e->id/inscripcion")->assertOk();
        $this->assertSame('confirmada', $second->fresh()->estado);
        $this->assertSame('espera', $third->fresh()->estado);
        $this->assertSame(1, RegistroEvento::where('estado', 'confirmada')->count());
        $this->assertSame(3, RegistroEvento::count());
        $this->assertNotSame($first->token_qr, $first->fresh()->token_qr);
        $this->actingAs($this->andrea)->getJson("/api/eventos/$e->id/boleto")->assertJsonPath('qr_habilitado', true);
    }

    public function test_capacity_increase_promotes_and_reserved_event_terms_are_protected(): void
    {
        $e = $this->evento();
        $this->inscribir($this->estudiante, $e);
        $wait = $this->inscribir($this->andrea, $e, 'espera');
        $this->actingAs($this->presidente)->putJson("/api/eventos/$e->id", $this->payload(['capacidad' => 2]))->assertOk();
        $this->assertSame('confirmada', $wait->fresh()->estado);
        $this->putJson("/api/eventos/$e->id", $this->payload())->assertUnprocessable()->assertJsonValidationErrors('capacidad');
        $this->putJson("/api/eventos/$e->id", $this->payload(['capacidad' => 2, 'costo' => 50]))->assertUnprocessable()->assertJsonValidationErrors('costo');
        $this->putJson("/api/eventos/$e->id", $this->payload(['capacidad' => 2, 'ubicacion' => 'Otro lugar']))->assertUnprocessable()->assertJsonValidationErrors('ubicacion');
    }

    public function test_closed_registration_and_full_events_without_waiting_list_reject_booking(): void
    {
        $e = $this->evento(['lista_espera' => false]);
        $this->inscribir($this->estudiante, $e);
        $this->actingAs($this->andrea)->postJson("/api/eventos/$e->id/inscripcion")->assertUnprocessable();
        $e->update(['fecha_inicio_registro' => now()->addMinutes(5)]);
        $this->postJson("/api/eventos/$e->id/inscripcion")->assertUnprocessable();
        $e->update(['fecha_inicio_registro' => now()->subHour(), 'fecha_fin_registro' => now()]);
        $this->postJson("/api/eventos/$e->id/inscripcion")->assertUnprocessable();
        $this->assertSame(1, RegistroEvento::count());
    }

    public function test_paid_reservation_occupies_a_seat_but_never_issues_access(): void
    {
        $e = $this->evento(['costo' => 125.50]);
        $reg = $this->inscribir($this->estudiante, $e);
        $this->assertSame('pendiente', $reg->estado_pago);
        $this->assertSame(12550, $reg->monto_centavos);
        $this->getJson("/api/eventos/$e->id/boleto")->assertJsonPath('qr_habilitado', false)->assertJsonPath('token_qr', null);
        $this->inscribir($this->andrea, $e, 'espera');
        $this->actingAs($this->presidente)->postJson('/api/eventos/checkin', ['evento_id' => (string) $e->id, 'token_qr' => $reg->token_qr])->assertUnprocessable();
        $this->actingAs($this->estudiante)->deleteJson("/api/eventos/$e->id/inscripcion")->assertOk();
        $this->assertSame('pendiente', RegistroEvento::where('usuario_id', (string) $this->andrea->id)->firstOrFail()->estado_pago);
    }

    public function test_cancellation_and_rebooking_invalidate_the_original_token(): void
    {
        $e = $this->evento();
        $reg = $this->inscribir($this->estudiante, $e);
        $this->deleteJson("/api/eventos/$e->id/inscripcion")->assertOk();
        $this->getJson("/api/eventos/$e->id/boleto")->assertJsonPath('qr_habilitado', false);
        $new = $this->inscribir($this->estudiante, $e);
        $this->assertNotSame($reg->token_qr, $new->token_qr);
        $this->assertSame(1, RegistroEvento::count());
        $this->actingAs($this->presidente)->postJson('/api/eventos/checkin', ['evento_id' => (string) $e->id, 'token_qr' => $reg->token_qr])->assertUnprocessable();
        $this->postJson('/api/eventos/checkin', ['evento_id' => (string) $e->id, 'token_qr' => $new->token_qr])->assertOk();
    }

    public function test_event_cancellation_revokes_all_tickets_and_cannot_be_republished(): void
    {
        $e = $this->evento();
        $reg = $this->inscribir($this->estudiante, $e);
        $this->inscribir($this->andrea, $e, 'espera');
        $this->actingAs($this->presidente)->postJson("/api/eventos/$e->id/cancelar", ['motivo' => 'Cambio de calendario académico'])->assertOk();
        $this->postJson("/api/eventos/$e->id/cancelar", ['motivo' => 'Cambio de calendario académico'])->assertOk();
        $this->assertSame(2, RegistroEvento::where('estado', 'cancelada')->count());
        $this->postJson("/api/eventos/$e->id/publicar")->assertUnprocessable();
        $this->postJson('/api/eventos/checkin', ['evento_id' => (string) $e->id, 'token_qr' => $reg->token_qr])->assertUnprocessable();
        $this->actingAs($this->estudiante)->getJson("/api/eventos/$e->id/boleto")->assertJsonPath('estado', 'cancelada')->assertJsonPath('token_qr', null);
        $this->getJson('/api/eventos')->assertJsonCount(0, 'eventos');
    }

    public function test_checkin_has_time_limits_and_cannot_be_reused_or_cancelled_after_attendance(): void
    {
        $e = $this->evento();
        $reg = $this->inscribir($this->estudiante, $e);
        $payload = ['evento_id' => (string) $e->id, 'token_qr' => $reg->token_qr];
        $this->travel(-11)->minutes();
        $this->actingAs($this->presidente)->postJson('/api/eventos/checkin', $payload)->assertUnprocessable();
        $this->travel(11)->minutes();
        $this->postJson('/api/eventos/checkin', $payload)->assertOk();
        $this->postJson('/api/eventos/checkin', $payload)->assertUnprocessable();
        $this->postJson("/api/eventos/$e->id/cancelar", ['motivo' => 'Cancelar con asistencia registrada'])->assertUnprocessable();
        $this->actingAs($this->estudiante)->deleteJson("/api/eventos/$e->id/inscripcion")->assertUnprocessable();
        $this->getJson("/api/eventos/$e->id/boleto")->assertJsonPath('token_qr', null)->assertJsonPath('estado_asistencia', 'asistio');
        $this->travel(2)->hours();
        $this->actingAs($this->presidente)->postJson('/api/eventos/checkin', $payload)->assertUnprocessable();
    }

    public function test_started_event_rejects_cancellations_and_inactive_organization_rejects_new_registrations(): void
    {
        $e = $this->evento();
        $this->inscribir($this->estudiante, $e);
        $this->org->update(['estado' => 'inactiva']);
        $this->actingAs($this->andrea)->postJson("/api/eventos/$e->id/inscripcion")->assertNotFound();
        $this->getJson('/api/eventos')->assertJsonCount(0, 'eventos');
        $this->org->update(['estado' => 'activa']);
        $this->travel(20)->minutes();
        $this->actingAs($this->estudiante)->deleteJson("/api/eventos/$e->id/inscripcion")->assertUnprocessable();
        $this->actingAs($this->presidente)->postJson("/api/eventos/$e->id/cancelar", ['motivo' => 'Evento que ya está comenzando'])->assertUnprocessable();
    }

    public function test_future_payment_integration_will_require_refunds_before_cancellation(): void
    {
        $e = $this->evento(['costo' => 25]);
        $reg = $this->inscribir($this->estudiante, $e);
        // Fixture del estado que solo podrá confirmar una integración confiable.
        $reg->update(['estado_pago' => 'pagado']);
        $this->deleteJson("/api/eventos/$e->id/inscripcion")->assertUnprocessable();
        $this->actingAs($this->presidente)->postJson("/api/eventos/$e->id/cancelar", ['motivo' => 'Evento con pagos confirmados'])->assertUnprocessable();
    }
}
