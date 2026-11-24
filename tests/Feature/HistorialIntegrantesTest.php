<?php

namespace Tests\Feature;

use App\Models\AuditoriaComunidad;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\User;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

/*
 * Incidencia E6 (20-nov-2026): al administrar una asociación, agregar o quitar integrantes no aparecía en el historial.
 * Diagnóstico: las altas y bajas sí se auditaban (miembro_registrado / miembro_baja, en la misma transacción que el cambio),
 * pero el único historial visible era «Cambios de cargos» de cada periodo, que filtra sólo acciones de cargos. Además,
 * reintentar una baja volvía a auditarla. La corrección reutiliza auditoria_comunidad: un historial de integrantes de la
 * organización activa, visible para quien la administra, y una baja repetida se rechaza sin auditar.
 */
class HistorialIntegrantesTest extends TestCase
{
    use RefreshMongoDatabase;

    protected User $presidente;

    protected User $estudiante;

    protected Organizacion $org;

    protected Organizacion $consejo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->presidente = User::where('email', 'presidencia@campus.test')->firstOrFail();
        $this->estudiante = User::where('email', 'estudiante@campus.test')->firstOrFail();
        $this->org = Organizacion::where('slug', 'sistemas')->firstOrFail();
        $this->consejo = Organizacion::where('slug', 'consejo')->firstOrFail();
    }

    private function miembro(User $user): MiembroOrganizacion
    {
        return MiembroOrganizacion::where('organizacion_id', (string) $this->org->id)->where('usuario_id', (string) $user->id)->firstOrFail();
    }

    private function historial(): array
    {
        return $this->getJson('/api/organizaciones/miembros/historial')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
    }

    public function test_removing_and_adding_a_member_is_persisted_and_shown_in_the_member_history(): void
    {
        $this->actingAs($this->presidente);
        $miembro = $this->miembro($this->estudiante);

        $this->deleteJson('/api/organizaciones/miembros/'.$miembro->id)->assertOk();
        $this->assertSame('inactivo', $miembro->fresh()->estado);
        $h = $this->historial();
        $this->assertCount(1, $h['movimientos']);
        $baja = $h['movimientos'][0];
        $this->assertSame('eliminacion', $baja['operacion']);
        $this->assertSame((string) $this->org->id, $baja['organizacion_id']);
        $this->assertSame($this->org->nombre, $baja['organizacion_nombre']);
        $this->assertSame((string) $this->estudiante->id, $baja['integrante']['usuario_id']);
        $this->assertSame($this->estudiante->name, $baja['integrante']['nombre']);
        $this->assertSame('20260002', $baja['integrante']['matricula']);
        $this->assertSame((string) $this->presidente->id, $baja['realizado_por']['usuario_id']);
        $this->assertSame($this->presidente->name, $baja['realizado_por']['nombre']);
        $this->assertNotEmpty($baja['fecha']);
        $this->assertSame('activo', $baja['estado_anterior']);
        $this->assertSame('inactivo', $baja['estado_posterior']);

        $this->postJson('/api/organizaciones/miembros', ['matricula' => '20260002', 'fecha_inicio' => today()->toDateString()])->assertCreated();
        $this->assertSame('activo', $miembro->fresh()->estado);
        $h = $this->historial();
        $this->assertCount(2, $h['movimientos']);
        $alta = $h['movimientos'][0]; // más reciente primero
        $this->assertSame('incorporacion', $alta['operacion']);
        $this->assertSame('inactivo', $alta['estado_anterior']);
        $this->assertSame('activo', $alta['estado_posterior']);
        $this->assertSame((string) $this->estudiante->id, $alta['integrante']['usuario_id']);

        // Persiste: una consulta nueva (recarga) devuelve lo mismo, y sin datos sensibles.
        $this->assertSame($h['movimientos'], $this->historial()['movimientos']);
        $json = json_encode($h);
        foreach (['password', 'token', 'secret', 'two_factor'] as $sensible) {
            $this->assertStringNotContainsString($sensible, $json);
        }
    }

    public function test_a_brand_new_member_is_recorded_as_an_incorporation_without_previous_state(): void
    {
        $nuevo = User::factory()->create(['matricula' => 'HIST-0001', 'name' => 'Integrante Nuevo']);
        $this->actingAs($this->presidente)->postJson('/api/organizaciones/miembros', ['matricula' => 'HIST-0001', 'fecha_inicio' => today()->toDateString()])->assertCreated();
        $alta = $this->historial()['movimientos'][0];
        $this->assertSame('incorporacion', $alta['operacion']);
        $this->assertSame('Integrante Nuevo', $alta['integrante']['nombre']);
        $this->assertNull($alta['estado_anterior']);
        $this->assertSame('activo', $alta['estado_posterior']);
    }

    public function test_members_without_authority_cannot_modify_members_nor_read_the_history(): void
    {
        $this->actingAs($this->estudiante);
        $this->postJson('/api/organizaciones/miembros', ['matricula' => '20260003', 'fecha_inicio' => today()->toDateString()])->assertForbidden();
        $this->deleteJson('/api/organizaciones/miembros/'.$this->miembro($this->presidente)->id)->assertForbidden();
        $this->getJson('/api/organizaciones/miembros/historial')->assertForbidden();
        $this->assertSame(0, AuditoriaComunidad::whereIn('accion', ['miembro_registrado', 'miembro_baja'])->count());
    }

    public function test_the_history_only_shows_the_active_organization(): void
    {
        AuditoriaComunidad::create(['organizacion_id' => (string) $this->consejo->id, 'usuario_id' => (string) $this->presidente->id,
            'accion' => 'miembro_baja', 'entidad_id' => 'x', 'antes' => ['usuario_id' => (string) $this->estudiante->id, 'estado' => 'activo'],
            'despues' => ['usuario_id' => (string) $this->estudiante->id, 'estado' => 'inactivo']]);
        AuditoriaComunidad::create(['organizacion_id' => (string) $this->org->id, 'usuario_id' => (string) $this->presidente->id,
            'accion' => 'perfil_actualizado', 'entidad_id' => (string) $this->org->id, 'antes' => [], 'despues' => []]);
        $this->actingAs($this->presidente);
        $this->assertSame([], $this->historial()['movimientos']);
        $this->postJson('/api/organizaciones/seleccionar', ['organizacion_id' => (string) $this->consejo->id])->assertForbidden();
    }

    public function test_a_failed_removal_leaves_no_success_record(): void
    {
        $this->actingAs($this->presidente);
        $this->deleteJson('/api/organizaciones/miembros/'.$this->miembro($this->presidente)->id)->assertUnprocessable();
        $this->assertSame('activo', $this->miembro($this->presidente)->fresh()->estado);
        $this->assertSame(0, AuditoriaComunidad::where('accion', 'miembro_baja')->count());
        $this->assertSame([], $this->historial()['movimientos']);
    }

    public function test_retrying_an_addition_or_a_removal_does_not_duplicate_history(): void
    {
        $this->actingAs($this->presidente);
        $miembro = $this->miembro($this->estudiante);
        $this->deleteJson('/api/organizaciones/miembros/'.$miembro->id)->assertOk();
        $this->deleteJson('/api/organizaciones/miembros/'.$miembro->id)->assertUnprocessable()->assertJsonValidationErrors('miembro');
        $add = fn () => $this->postJson('/api/organizaciones/miembros', ['matricula' => '20260002', 'fecha_inicio' => today()->toDateString()]);
        $add()->assertCreated();
        $add()->assertUnprocessable();
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'miembro_baja')->count());
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'miembro_registrado')->count());
        $this->assertCount(2, $this->historial()['movimientos']);
    }
}
