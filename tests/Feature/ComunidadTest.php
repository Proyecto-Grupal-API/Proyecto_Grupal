<?php

namespace Tests\Feature;

use App\Models\AuditoriaComunidad;
use App\Models\Evento;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\RegistroEvento;
use App\Models\RolOrganizacion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class ComunidadTest extends TestCase
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

    public function test_guest_cannot_read_or_modify_organizations(): void
    {
        $this->getJson('/api/organizaciones')->assertUnauthorized();
        $this->postJson('/api/organizaciones/miembros', [])->assertUnauthorized();
        $this->get('/modulo6/asociacion')->assertRedirect('/login');
    }

    public function test_session_user_sees_only_their_organization_with_real_names(): void
    {
        $this->actingAs($this->presidente)->getJson('/api/organizaciones')->assertOk()
            ->assertJsonPath('organizacion.id', (string) $this->org->id)
            ->assertJsonPath('puede_editar', true)->assertJsonCount(1, 'organizaciones')
            ->assertJsonCount(2, 'miembros')->assertJsonFragment(['matricula' => '20260002']);
        $this->get('/modulo6/asociacion')->assertOk();
    }

    public function test_member_can_switch_only_between_organizations_they_belong_to(): void
    {
        $this->actingAs($this->estudiante)->postJson('/api/organizaciones/seleccionar', ['organizacion_id' => (string) $this->consejo->id])->assertOk();
        $this->getJson('/api/organizaciones')->assertJsonPath('organizacion.id', (string) $this->consejo->id)->assertJsonPath('puede_editar', false);
        $this->actingAs($this->presidente)->postJson('/api/organizaciones/seleccionar', ['organizacion_id' => (string) $this->consejo->id])->assertForbidden();
    }

    public function test_regular_member_cannot_modify_profile_or_grant_themself_a_role(): void
    {
        $this->actingAs($this->estudiante)->putJson('/api/organizaciones/perfil', ['nombre' => 'Hack', 'email' => 'x@example.test'])->assertForbidden();
        $this->postJson('/api/organizaciones/roles', ['usuario_id' => (string) $this->estudiante->id, 'slug_rol' => 'presidencia', 'fecha_inicio' => today()->toDateString()])->assertForbidden();
    }

    public function test_expired_or_future_presidency_does_not_grant_permissions(): void
    {
        $rol = RolOrganizacion::where('organizacion_id', (string) $this->org->id)->firstOrFail();
        $this->actingAs($this->presidente);
        $rol->update(['fecha_fin' => today()->subDay()]);
        $this->putJson('/api/organizaciones/perfil', ['nombre' => 'Hack', 'email' => 'x@example.test'])->assertForbidden();
        $rol->update(['fecha_inicio' => today()->addDay(), 'fecha_fin' => today()->addMonth()]);
        $this->putJson('/api/organizaciones/perfil', ['nombre' => 'Hack', 'email' => 'x@example.test'])->assertForbidden();
    }

    public function test_profile_validates_and_ignores_foreign_organization_ids(): void
    {
        $this->actingAs($this->presidente)->putJson('/api/organizaciones/perfil', ['nombre' => '', 'email' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors(['nombre', 'email']);
        $this->putJson('/api/organizaciones/perfil', ['nombre' => 'Sistemas actualizado', 'email' => 'sis@example.test', 'organizacion_id' => (string) $this->consejo->id])->assertOk();
        $this->assertSame('Sistemas actualizado', $this->org->fresh()->nombre);
        $this->assertSame('Consejo Estudiantil', $this->consejo->fresh()->nombre);
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'perfil_actualizado')->count());
    }

    public function test_add_member_uses_registered_matricula_and_prevents_duplicates(): void
    {
        $this->actingAs($this->presidente);
        $this->postJson('/api/organizaciones/miembros', ['matricula' => 'missing', 'fecha_inicio' => today()->toDateString()])->assertUnprocessable();
        $this->postJson('/api/organizaciones/miembros', ['matricula' => '20260004', 'fecha_inicio' => today()->toDateString(), 'rol_interno' => 'Presidente'])->assertCreated();
        $andrea = User::where('matricula', '20260004')->firstOrFail();
        $this->assertTrue(MiembroOrganizacion::activos()->where('usuario_id', (string) $andrea->id)->exists());
        $this->assertFalse(RolOrganizacion::where('usuario_id', (string) $andrea->id)->exists());
        $this->postJson('/api/organizaciones/miembros', ['matricula' => '20260004', 'fecha_inicio' => today()->toDateString()])->assertUnprocessable();
    }

    public function test_roles_require_active_members_and_valid_dates_and_unique_cargo(): void
    {
        $other = User::where('email', 'consejo@campus.test')->firstOrFail();
        $payload = ['usuario_id' => (string) $other->id, 'slug_rol' => 'tesoreria', 'fecha_inicio' => today()->toDateString()];
        $this->actingAs($this->presidente)->postJson('/api/organizaciones/roles', $payload)->assertUnprocessable();
        $payload['usuario_id'] = (string) $this->estudiante->id;
        $this->postJson('/api/organizaciones/roles', [...$payload, 'fecha_fin' => today()->subDay()->toDateString()])->assertUnprocessable();
        $this->postJson('/api/organizaciones/roles', $payload)->assertCreated();
        $this->postJson('/api/organizaciones/roles', $payload)->assertUnprocessable();
    }

    public function test_cannot_remove_a_member_or_role_of_another_organization(): void
    {
        $miembro = MiembroOrganizacion::where('organizacion_id', (string) $this->consejo->id)->firstOrFail();
        $rol = RolOrganizacion::where('organizacion_id', (string) $this->consejo->id)->firstOrFail();
        $this->actingAs($this->presidente)->deleteJson('/api/organizaciones/miembros/'.$miembro->id)->assertNotFound();
        $this->deleteJson('/api/organizaciones/roles/'.$rol->id)->assertNotFound();
        $this->assertNull($miembro->fresh()->eliminado_en);
    }

    public function test_member_removal_revokes_roles_and_reactivation_does_not_restore_them(): void
    {
        $this->actingAs($this->presidente)->postJson('/api/organizaciones/roles', ['usuario_id' => (string) $this->estudiante->id, 'slug_rol' => 'tesoreria', 'fecha_inicio' => today()->toDateString()])->assertCreated();
        $miembro = MiembroOrganizacion::where('organizacion_id', (string) $this->org->id)->where('usuario_id', (string) $this->estudiante->id)->firstOrFail();
        $this->deleteJson('/api/organizaciones/miembros/'.$miembro->id)->assertOk();
        $this->assertSame('inactivo', $miembro->fresh()->estado);
        $this->assertFalse(RolOrganizacion::vigentes()->where('organizacion_id', (string) $this->org->id)->where('usuario_id', (string) $this->estudiante->id)->exists());
        $this->postJson('/api/organizaciones/miembros', ['matricula' => '20260002', 'fecha_inicio' => today()->toDateString()])->assertCreated();
        $this->assertSame(1, MiembroOrganizacion::where('organizacion_id', (string) $this->org->id)->where('usuario_id', (string) $this->estudiante->id)->count());
        $this->assertFalse(RolOrganizacion::vigentes()->where('organizacion_id', (string) $this->org->id)->where('usuario_id', (string) $this->estudiante->id)->exists());
    }

    public function test_presidency_transfer_revokes_old_manager_and_grants_new_one(): void
    {
        $rol = RolOrganizacion::where('organizacion_id', (string) $this->org->id)->firstOrFail();
        $this->actingAs($this->presidente)->deleteJson('/api/organizaciones/roles/'.$rol->id)->assertUnprocessable();
        $this->putJson('/api/organizaciones/roles/'.$rol->id, ['usuario_id' => (string) $this->estudiante->id, 'slug_rol' => 'presidencia', 'fecha_inicio' => today()->toDateString(), 'fecha_fin' => today()->addYear()->toDateString()])->assertOk();
        $this->putJson('/api/organizaciones/perfil', ['nombre' => 'No permitido', 'email' => 'a@b.test'])->assertForbidden();
        $this->actingAs($this->estudiante)->putJson('/api/organizaciones/perfil', ['nombre' => 'Nueva presidencia', 'email' => 'a@b.test'])->assertOk();
    }

    public function test_seeder_is_repeatable_and_preserves_profile_changes(): void
    {
        $this->org->update(['nombre' => 'Personalizado']);
        $this->seed();
        $this->assertSame(4, User::count());
        $this->assertSame(2, Organizacion::count());
        $this->assertSame(4, MiembroOrganizacion::count());
        $this->assertSame(2, RolOrganizacion::count());
        $this->assertSame('Personalizado', $this->org->fresh()->nombre);
    }

    public function test_existing_read_screens_work_on_empty_mongodb_collections(): void
    {
        $this->actingAs($this->presidente);
        foreach (['dashboard', 'eventos', 'becas', 'comunicacion', 'transparencia', 'notificaciones'] as $endpoint) {
            $this->getJson('/api/'.$endpoint)->assertOk();
        }
        $this->getJson('/api/dashboard')->assertJsonPath('cajaDisponible', null)->assertJsonPath('miembrosActivos', 2);
    }

    public function test_mongodb_beca_relationships_are_scoped_and_dynamic(): void
    {
        $id = (string) $this->org->id;
        $tipo = (string) DB::table('tipos_beneficio')->insertGetId(['nombre' => 'Comedor', 'es_monetario' => false, 'es_servicio' => true]);
        $conv = (string) DB::table('convocatorias_becas')->insertGetId(['organizacion_id' => $id, 'tipo_beneficio_id' => $tipo, 'titulo' => 'Beca comedor', 'estado' => 'publicada', 'total_espacios' => 2]);
        $sol = (string) DB::table('solicitudes_becas')->insertGetId(['organizacion_id' => $id, 'convocatoria_id' => $conv, 'usuario_id' => (string) $this->estudiante->id, 'estado' => 'aprobada', 'enviado_en' => now(), 'creado_en' => now()]);
        DB::table('asignaciones_beneficios')->insert(['organizacion_id' => $id, 'solicitud_id' => $sol]);
        DB::table('convocatorias_becas')->insert(['organizacion_id' => (string) $this->consejo->id, 'titulo' => 'Privada de otra organización', 'estado' => 'publicada']);
        $this->actingAs($this->presidente)->getJson('/api/becas?gestion=1')->assertOk()->assertJsonCount(1, 'convocatorias')->assertJsonPath('convocatorias.0.espacios_ocupados', 1)->assertJsonPath('convocatorias.0.porcentaje', 50);
        $this->getJson('/api/becas/'.$conv.'/solicitudes')->assertOk()->assertJsonPath('convocatoria.titulo', 'Beca comedor')->assertJsonPath('solicitudes.0.nombre', $this->estudiante->name);
    }

    public function test_notifications_are_read_and_updated_only_for_the_recipient(): void
    {
        foreach ([$this->presidente, $this->estudiante] as $u) {
            DB::table('mensajes')->insert(['usuario_id' => (string) $u->id, 'asunto' => 'Para '.$u->matricula, 'cuerpo' => 'Mensaje', 'creado_en' => now(), 'leido_en' => null, 'eliminado_en' => null]);
        }
        $this->actingAs($this->presidente)->getJson('/api/notificaciones')->assertOk()->assertJsonCount(1)->assertJsonPath('0.titulo', 'Para 20260001');
        $this->putJson('/api/notificaciones/leer')->assertOk();
        $this->deleteJson('/api/notificaciones/leidas')->assertOk();
        $ajeno = DB::table('mensajes')->where('usuario_id', (string) $this->estudiante->id)->first();
        $this->assertNull($ajeno->leido_en);
        $this->assertNull($ajeno->eliminado_en);
    }

    public function test_event_writes_are_validated_and_checkin_cannot_be_repeated(): void
    {
        $this->actingAs($this->presidente)->postJson('/api/eventos', ['titulo' => 'Incompleto'])->assertUnprocessable();
        $evento = Evento::create(['organizacion_id' => (string) $this->org->id, 'titulo' => 'Evento demo', 'estado' => 'publicado', 'capacidad' => 10, 'fecha_hora_inicio' => now()->addMinutes(15), 'fecha_hora_fin' => now()->addHours(2)]);
        RegistroEvento::create(['evento_id' => (string) $evento->id, 'usuario_id' => (string) $this->estudiante->id, 'estado' => 'confirmada', 'token_qr' => 'prueba-unica', 'estado_pago' => 'exento', 'estado_asistencia' => 'pendiente', 'registrado_en' => now()]);
        $payload = ['evento_id' => (string) $evento->id, 'estado' => 'confirmada', 'token_qr' => 'prueba-unica'];
        $this->postJson('/api/eventos/checkin', $payload)->assertOk();
        $this->postJson('/api/eventos/checkin', $payload)->assertUnprocessable();
        $this->actingAs($this->estudiante)->postJson('/api/eventos/checkin', $payload)->assertForbidden();
        $this->getJson('/api/estudiante/mi-boleto')->assertOk()->assertJsonPath('estado_asistencia', 'asistio')->assertJsonPath('token_qr', null);
    }

    public function test_stale_browser_organization_context_cannot_modify_another_profile(): void
    {
        $this->actingAs($this->presidente)->withHeader('X-Organization-Id', (string) $this->consejo->id)
            ->putJson('/api/organizaciones/perfil', ['nombre' => 'Incorrecto', 'email' => 'x@example.test'])->assertConflict();
        $this->assertSame('Asociación de Sistemas', $this->org->fresh()->nombre);
    }
}
