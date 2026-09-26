<?php

namespace Tests\Feature;

use App\Models\AuditoriaComunidad;
use App\Models\Evento;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\RegistroEvento;
use App\Models\StaffEvento;
use App\Models\User;
use Database\Seeders\StaffEventoSeeder;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class StaffEventosTest extends TestCase
{
    use RefreshMongoDatabase;

    private User $admin;

    private User $staff;

    private User $asistente;

    private Organizacion $org;

    private Evento $evento;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute());
        $this->seed();
        $this->admin = User::where('email', 'presidencia@campus.test')->firstOrFail();
        $this->staff = User::where('email', 'estudiante@campus.test')->firstOrFail();
        $this->asistente = User::where('email', 'andrea@campus.test')->firstOrFail();
        $this->org = Organizacion::where('slug', 'sistemas')->firstOrFail();
        $this->evento = $this->evento();
    }

    private function evento(array $extra = []): Evento
    {
        return Evento::create([...['organizacion_id' => (string) $this->org->id, 'titulo' => 'Conferencia de prueba', 'estado' => 'publicado', 'capacidad' => 5, 'fecha_hora_inicio' => now()->addMinutes(20), 'fecha_hora_fin' => now()->addHours(2), 'ubicacion' => 'Auditorio'], ...$extra]);
    }

    private function asignar(?Evento $e = null): StaffEvento
    {
        $e ??= $this->evento;
        $this->actingAs($this->admin)->postJson('/api/eventos/'.$e->id.'/staff', ['usuario_id' => (string) $this->staff->id])->assertOk();

        return StaffEvento::where('evento_id', (string) $e->id)->where('usuario_id', (string) $this->staff->id)->firstOrFail();
    }

    private function boleto(array $extra = []): RegistroEvento
    {
        return RegistroEvento::create([...['evento_id' => (string) $this->evento->id, 'usuario_id' => (string) $this->asistente->id, 'estado' => 'confirmada', 'estado_pago' => 'exento', 'estado_asistencia' => 'pendiente', 'token_qr' => (string) Str::uuid(), 'registrado_en' => now()], ...$extra]);
    }

    private function scan(RegistroEvento $b, ?Evento $e = null)
    {
        return $this->postJson('/api/eventos/checkin', ['evento_id' => (string) ($e ?? $this->evento)->id, 'token_qr' => $b->token_qr]);
    }

    public function test_guest_is_denied_and_unassigned_member_has_no_access(): void
    {
        $this->getJson('/api/staff/eventos')->assertUnauthorized();
        $this->get('/modulo6/staff')->assertRedirect('/login');
        $b = $this->boleto();
        $this->actingAs($this->staff)->getJson('/api/staff/eventos')->assertOk()->assertJsonCount(0, 'eventos');
        $this->getJson('/api/staff/eventos/'.$this->evento->id)->assertForbidden();
        $this->scan($b)->assertForbidden();
    }

    public function test_only_admin_assigns_existing_members_and_assignment_is_idempotent(): void
    {
        $url = '/api/eventos/'.$this->evento->id.'/staff';
        $this->actingAs($this->staff)->postJson($url, ['usuario_id' => (string) $this->staff->id])->assertForbidden();
        $this->actingAs($this->admin)->postJson($url, ['usuario_id' => (string) $this->asistente->id])->assertUnprocessable();
        $this->asignar();
        $this->asignar();
        $this->assertSame(1, StaffEvento::count());
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'evento_staff_asignado')->count());
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'staff')->assertJsonCount(2, 'integrantes');
    }

    public function test_staff_sees_only_assigned_live_events_without_ticket_or_other_private_data(): void
    {
        $this->asignar();
        $this->evento(['titulo' => 'No asignado']);
        $this->asignar($this->evento(['titulo' => 'Borrador', 'estado' => 'borrador']));
        $b = $this->boleto();
        $this->actingAs($this->staff)->get('/modulo6/staff')->assertOk();
        $res = $this->getJson('/api/staff/eventos')->assertOk()->assertJsonCount(1, 'eventos')->assertJsonPath('eventos.0.por_ingresar', 1);
        $res->assertDontSee($b->token_qr)->assertDontSee($this->asistente->name)->assertDontSee($this->asistente->email);
        $this->getJson('/api/eventos/'.$this->evento->id.'/inscritos')->assertForbidden();
        $this->getJson('/api/eventos/'.$this->evento->id.'/staff')->assertForbidden();
        $this->postJson('/api/eventos/'.$this->evento->id.'/cancelar', ['motivo' => 'Sin autoridad para cancelar'])->assertForbidden();
        $this->getJson('/api/becas?gestion=1')->assertForbidden();
    }

    public function test_staff_checks_in_once_and_audit_identifies_scanner_without_exposing_qr(): void
    {
        $this->asignar();
        $b = $this->boleto();
        $this->actingAs($this->staff);
        $this->scan($b)->assertOk()->assertJsonPath('asistente.name', $this->asistente->name)->assertDontSee($b->token_qr);
        $this->assertSame((string) $this->staff->id, $b->fresh()->asistencia_por);
        $this->scan($b)->assertUnprocessable();
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'evento_asistencia')->where('usuario_id', (string) $this->staff->id)->count());
        $this->getJson('/api/staff/eventos/'.$this->evento->id)->assertJsonPath('evento.asistencias', 1)->assertJsonPath('evento.por_ingresar', 0);
    }

    public function test_invalid_pending_cancelled_waiting_or_other_event_tickets_never_record_attendance(): void
    {
        $this->asignar();
        $b = $this->boleto();
        $otro = $this->evento();
        $this->asignar($otro);
        $this->actingAs($this->staff);
        foreach ([['estado_pago' => 'pendiente'], ['estado_pago' => 'exento', 'estado' => 'cancelada'], ['estado' => 'espera']] as $estado) {
            $b->update($estado);
            $this->scan($b)->assertUnprocessable();
        }
        $b->update(['estado' => 'confirmada']);
        $this->scan($b, $otro)->assertUnprocessable();
        $this->postJson('/api/eventos/checkin', ['evento_id' => (string) $this->evento->id, 'token_qr' => 'invalido'])->assertUnprocessable();
        $this->assertSame('pendiente', $b->fresh()->estado_asistencia);
    }

    public function test_access_window_and_event_state_are_enforced_on_server(): void
    {
        $this->asignar();
        $b = $this->boleto();
        $this->actingAs($this->staff);
        $this->evento->update(['fecha_hora_inicio' => now()->addMinutes(31)]);
        $this->scan($b)->assertUnprocessable();
        $this->evento->update(['fecha_hora_inicio' => now()->subHour(), 'fecha_hora_fin' => now()]);
        $this->scan($b)->assertUnprocessable();
        $this->evento->update(['fecha_hora_fin' => now()->addHour(), 'estado' => 'cancelado']);
        $this->scan($b)->assertUnprocessable();
        $this->evento->update(['estado' => 'publicado', 'fecha_hora_inicio' => now()->addMinutes(30)]);
        $this->scan($b)->assertOk();
    }

    public function test_revocation_takes_effect_on_open_staff_page_and_can_be_reassigned(): void
    {
        $s = $this->asignar();
        $b = $this->boleto();
        $this->actingAs($this->staff)->getJson('/api/staff/eventos/'.$this->evento->id)->assertOk();
        $this->actingAs($this->admin)->deleteJson('/api/eventos/'.$this->evento->id.'/staff/'.$s->id)->assertOk();
        $this->deleteJson('/api/eventos/'.$this->evento->id.'/staff/'.$s->id)->assertOk();
        $this->actingAs($this->staff);
        $this->scan($b)->assertForbidden();
        $this->getJson('/api/staff/eventos/'.$this->evento->id)->assertForbidden();
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'evento_staff_retirado')->count());
        $this->asignar();
        $this->assertSame(1, StaffEvento::count());
        $this->actingAs($this->staff);
        $this->scan($b)->assertOk();
    }

    public function test_membership_removal_revokes_staff_and_rejoining_does_not_restore_permission(): void
    {
        $s = $this->asignar();
        $b = $this->boleto();
        $m = MiembroOrganizacion::where('organizacion_id', (string) $this->org->id)->where('usuario_id', (string) $this->staff->id)->firstOrFail();
        $this->actingAs($this->admin)->deleteJson('/api/organizaciones/miembros/'.$m->id)->assertOk();
        $this->assertNotNull($s->fresh()->eliminado_en);
        $this->postJson('/api/organizaciones/miembros', ['matricula' => $this->staff->matricula, 'fecha_inicio' => today()->toDateString()])->assertCreated();
        $this->actingAs($this->staff);
        $this->scan($b)->assertForbidden();
    }

    public function test_cross_organization_event_and_stale_context_are_rejected(): void
    {
        $this->asignar();
        $b = $this->boleto();
        $consejo = Organizacion::where('slug', 'consejo')->firstOrFail();
        $e = $this->evento(['organizacion_id' => (string) $consejo->id]);
        $this->actingAs($this->admin)->getJson('/api/eventos/'.$e->id.'/staff')->assertNotFound();
        $this->actingAs($this->staff)->withSession(['organizacion_id' => (string) $consejo->id]);
        $this->getJson('/api/staff/eventos/'.$this->evento->id)->assertNotFound();
        $this->scan($b)->assertNotFound();
        $this->withHeader('X-Organization-Id', (string) $this->org->id);
        $this->scan($b)->assertConflict();
    }

    public function test_demo_seeder_is_repeatable_without_restoring_revoked_access_or_used_tickets(): void
    {
        $this->seed(StaffEventoSeeder::class);
        $demo = Evento::where('slug', 'conferencia-acceso-demo')->firstOrFail();
        $staff = StaffEvento::where('evento_id', (string) $demo->id)->firstOrFail();
        $staff->update(['eliminado_en' => now()]);
        $b = RegistroEvento::where('evento_id', (string) $demo->id)->firstOrFail();
        $b->update(['estado_asistencia' => 'asistio', 'asistio_en' => now()]);
        $inicio = $demo->fecha_hora_inicio;
        $this->seed(StaffEventoSeeder::class);
        $this->assertSame(1, User::where('email', 'staff@campus.test')->count());
        $this->assertSame(2, RegistroEvento::where('evento_id', (string) $demo->id)->count());
        $this->assertNotNull($staff->fresh()->eliminado_en);
        $this->assertSame('asistio', $b->fresh()->estado_asistencia);
        $this->assertTrue($inicio->equalTo($demo->fresh()->fecha_hora_inicio));
    }
}
