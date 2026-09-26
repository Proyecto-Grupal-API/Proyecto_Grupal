<?php

namespace Tests\Feature;

use App\Models\AuditoriaComunidad;
use App\Models\Campana;
use App\Models\Evento;
use App\Models\Mensaje;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\PermisoGestionOrganizaciones;
use App\Models\RegistroEvento;
use App\Models\RolOrganizacion;
use App\Models\StaffEvento;
use App\Models\User;
use App\Services\AutoridadOrganizaciones;
use App\Services\EntregaCampanas;
use Database\Seeders\GestionOrganizacionesSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class GestionOrganizacionesTest extends TestCase
{
    use RefreshMongoDatabase;

    private User $gestor;

    private User $presidente;

    private User $estudiante;

    private Organizacion $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->gestor = User::factory()->create(['matricula' => 'GESTOR-1']);
        PermisoGestionOrganizaciones::create(['usuario_id' => (string) $this->gestor->id, 'activo' => true]);
        $this->presidente = User::where('matricula', '20260001')->firstOrFail();
        $this->estudiante = User::where('matricula', '20260002')->firstOrFail();
        $this->org = Organizacion::where('slug', 'sistemas')->firstOrFail();
    }

    private function datos(array $extra = []): array
    {
        return [...['clave_alta' => (string) Str::uuid(), 'nombre' => 'Asociación de Robótica', 'tipo' => 'asociacion', 'descripcion' => 'Proyectos y talleres de robótica para la comunidad.', 'email' => 'robotica@example.test', 'telefono' => '', 'matricula_presidencia' => '20260002', 'fecha_fin_presidencia' => today()->addYear()->toDateString()], ...$extra];
    }

    private function cambio(string $estado = 'suspendida', int $version = 0, array $extra = []): array
    {
        return [...['estado' => $estado, 'version_estado' => $version, 'clave_cambio' => (string) Str::uuid(), 'motivo' => 'Cambio de estado autorizado para la revisión.'], ...$extra];
    }

    private function estado(array $d)
    {
        return $this->postJson('/api/gestion-organizaciones/'.$this->org->id.'/estado', $d);
    }

    public function test_guest_presidency_and_regular_member_cannot_manage_registry_or_lookup_users(): void
    {
        $this->getJson('/api/gestion-organizaciones')->assertUnauthorized();
        foreach ([$this->presidente, $this->estudiante] as $u) {
            $this->actingAs($u)->get('/modulo6/gestion-organizaciones')->assertForbidden();
            $this->getJson('/api/gestion-organizaciones')->assertForbidden();
            $this->getJson('/api/gestion-organizaciones/'.$this->org->id)->assertForbidden();
            $this->postJson('/api/gestion-organizaciones', $this->datos())->assertForbidden();
            $this->postJson('/api/gestion-organizaciones/titular', ['matricula' => '20260004'])->assertForbidden();
            $this->estado($this->cambio())->assertForbidden();
        }
        $this->assertSame(2, Organizacion::count());
    }

    public function test_creation_assigns_presidency_and_membership_without_giving_manager_domain_access(): void
    {
        $this->actingAs($this->gestor)->get('/modulo6/gestion-organizaciones')->assertOk();
        $this->postJson('/api/gestion-organizaciones/titular', ['matricula' => '20260002'])->assertOk()->assertJsonPath('titular.name', $this->estudiante->name)->assertDontSee($this->estudiante->email);
        $id = $this->postJson('/api/gestion-organizaciones', $this->datos(['estado' => 'suspendida', 'usuario_id' => (string) $this->gestor->id]))->assertCreated()->assertJsonPath('organizacion.estado', 'activa')->json('organizacion.id');
        $o = Organizacion::findOrFail($id);
        $this->assertTrue(Gate::forUser($this->estudiante)->allows('update', $o));
        $this->assertFalse(Gate::forUser($this->gestor)->allows('view', $o));
        $this->assertSame(1, MiembroOrganizacion::where('organizacion_id', $id)->count());
        $this->assertSame(1, RolOrganizacion::where('organizacion_id', $id)->count());
        $this->assertSame(1, AuditoriaComunidad::where('organizacion_id', $id)->count());
        $this->getJson('/api/gestion-organizaciones/'.$id)->assertJsonCount(1, 'historial')->assertDontSee('firma_alta')->assertDontSee('alta_datos');
        $this->getJson('/api/becas?gestion=1')->assertForbidden();
        $this->actingAs($this->estudiante)->postJson('/api/organizaciones/seleccionar', ['organizacion_id' => $id])->assertOk();
        $this->putJson('/api/organizaciones/perfil', ['nombre' => 'Robótica actualizada', 'email' => 'robotica@example.test'])->assertOk();
    }

    public function test_repeat_creation_never_duplicates_or_reactivates_and_changed_payload_conflicts(): void
    {
        $d = $this->datos();
        $this->actingAs($this->gestor);
        $id = $this->postJson('/api/gestion-organizaciones', $d)->assertCreated()->json('organizacion.id');
        $this->postJson('/api/gestion-organizaciones', $d)->assertCreated()->assertJsonPath('organizacion.id', $id);
        $this->postJson('/api/gestion-organizaciones', [...$d, 'nombre' => 'Otro nombre'])->assertConflict();
        $this->postJson('/api/gestion-organizaciones/'.$id.'/estado', $this->cambio('suspendida', 1))->assertOk();
        $this->postJson('/api/gestion-organizaciones', $d)->assertCreated()->assertJsonPath('organizacion.estado', 'suspendida');
        $this->assertSame(3, Organizacion::count());
        $this->assertSame(1, RolOrganizacion::where('organizacion_id', $id)->count());
        $this->assertSame(2, AuditoriaComunidad::where('organizacion_id', $id)->count());
    }

    public function test_bad_initial_data_and_duplicate_names_do_not_create_organizations(): void
    {
        $this->actingAs($this->gestor);
        foreach ([['nombre' => ''], ['tipo' => 'superadmin'], ['email' => 'invalido'], ['descripcion' => 'corta'], ['matricula_presidencia' => 'no-existe'], ['fecha_fin_presidencia' => today()->subDay()->toDateString()], ['clave_alta' => 'incorrecta'], ['nombre' => $this->org->nombre]] as $mal) {
            $this->postJson('/api/gestion-organizaciones', $this->datos($mal))->assertUnprocessable();
        }
        $this->assertSame(2, Organizacion::count());
    }

    public function test_interrupted_creation_stays_hidden_and_can_resume_without_duplicate_roles(): void
    {
        $interrumpir = true;
        Organizacion::updating(function ($o) use (&$interrumpir) {
            if ($interrumpir && $o->estado === 'activa') {
                $interrumpir = false;
                throw new \RuntimeException('Interrupción de prueba antes de publicar.');
            }
        });
        $d = $this->datos();
        $this->actingAs($this->gestor)->postJson('/api/gestion-organizaciones', $d)->assertStatus(500);
        $o = Organizacion::where('clave_alta', $d['clave_alta'])->firstOrFail();
        $this->assertSame('configurando', $o->estado);
        $this->assertFalse(Gate::forUser($this->estudiante)->allows('update', $o));
        $this->postJson('/api/gestion-organizaciones/'.$o->id.'/completar')->assertOk()->assertJsonPath('organizacion.estado', 'activa');
        $this->assertSame(1, RolOrganizacion::where('organizacion_id', (string) $o->id)->count());
        $this->assertSame(1, MiembroOrganizacion::where('organizacion_id', (string) $o->id)->count());
        $this->assertSame(1, AuditoriaComunidad::where('organizacion_id', (string) $o->id)->count());
        $this->assertSame($o->fresh()->historial_estados[0], AuditoriaComunidad::where('organizacion_id', (string) $o->id)->first()->despues);
    }

    public function test_state_history_is_versioned_idempotent_and_survives_reactivation(): void
    {
        $this->actingAs($this->gestor);
        $d = $this->cambio();
        $this->estado($d)->assertOk();
        $this->estado($d)->assertOk();
        $this->estado([...$d, 'motivo' => 'Un motivo diferente al anterior.'])->assertConflict();
        $this->estado($this->cambio('activa', 0))->assertConflict();
        $this->estado($this->cambio('activa', 1))->assertOk();
        $this->assertSame(2, count($this->org->fresh()->historial_estados));
        $this->assertSame(2, AuditoriaComunidad::where('accion', 'organizacion_estado')->count());
        $this->assertTrue(Gate::forUser($this->presidente)->allows('update', $this->org));
        $this->estado($d)->assertOk()->assertJsonPath('organizacion.estado', 'activa');
    }

    public function test_suspension_blocks_live_permissions_qr_catalog_and_new_registrations_but_keeps_history(): void
    {
        $e = Evento::create(['organizacion_id' => (string) $this->org->id, 'titulo' => 'Evento', 'estado' => 'publicado', 'capacidad' => 3, 'fecha_hora_inicio' => now()->addMinutes(20), 'fecha_hora_fin' => now()->addHours(2), 'fecha_inicio_registro' => now()->subHour(), 'fecha_fin_registro' => now()->addMinutes(20)]);
        $b = RegistroEvento::create(['evento_id' => (string) $e->id, 'usuario_id' => (string) $this->estudiante->id, 'estado' => 'confirmada', 'estado_pago' => 'exento', 'estado_asistencia' => 'pendiente', 'token_qr' => 'boleto-prueba']);
        $staff = StaffEvento::create(['evento_id' => (string) $e->id, 'organizacion_id' => (string) $this->org->id, 'usuario_id' => (string) $this->estudiante->id]);
        $this->assertTrue(Gate::forUser($this->presidente)->allows('update', $this->org));
        $this->actingAs($this->gestor);
        $this->estado($this->cambio())->assertOk();
        $this->assertFalse(Gate::forUser($this->presidente)->allows('update', $this->org));
        $this->actingAs($this->presidente)->putJson('/api/organizaciones/perfil', ['nombre' => 'No autorizado', 'email' => 'a@example.test'])->assertForbidden();
        $this->actingAs($this->estudiante)->getJson('/api/eventos/'.$e->id.'/boleto')->assertOk()->assertJsonPath('qr_habilitado', false)->assertJsonPath('token_qr', null);
        $this->getJson('/api/eventos')->assertJsonCount(0, 'eventos');
        $this->postJson('/api/eventos/'.$e->id.'/inscripcion')->assertNotFound();
        $this->postJson('/api/eventos/checkin', ['evento_id' => (string) $e->id, 'token_qr' => $b->token_qr])->assertStatus(404);
        $this->getJson('/api/organizaciones')->assertJsonCount(1, 'organizaciones_suspendidas');
        $this->assertNotNull($b->fresh());
        $this->assertNull($staff->fresh()->eliminado_en);
        $this->assertSame(2, MiembroOrganizacion::activos()->where('organizacion_id', (string) $this->org->id)->count());
        $this->actingAs($this->gestor);
        $this->estado($this->cambio('activa', 1))->assertOk();
        $this->actingAs($this->estudiante)->getJson('/api/eventos/'.$e->id.'/boleto')->assertJsonPath('qr_habilitado', true);
    }

    public function test_reactivation_requires_current_presidency_and_console_recovery_preserves_suspension(): void
    {
        $this->actingAs($this->gestor);
        $this->estado($this->cambio())->assertOk();
        $opts = ['organizacion' => 'sistemas', 'matricula' => '20260002', '--hasta' => today()->addYear()->toDateString(), '--operador' => 'Responsable autorizado', '--motivo' => 'Recuperar presidencia del periodo vencido.'];
        $this->artisan('comunidad:recuperar-presidencia', $opts)->assertFailed();
        RolOrganizacion::where('organizacion_id', (string) $this->org->id)->where('slug_rol', 'presidencia')->first()->update(['fecha_fin' => today()->subDay()]);
        $this->estado($this->cambio('activa', 1))->assertUnprocessable();
        $this->artisan('comunidad:recuperar-presidencia', $opts)->assertSuccessful();
        $this->assertSame('suspendida', $this->org->fresh()->estado);
        $this->estado($this->cambio('activa', 1))->assertOk();
        $this->assertTrue(Gate::forUser($this->estudiante)->allows('update', $this->org));
    }

    public function test_suspended_campaign_pauses_before_delivering_a_batch(): void
    {
        $c = Campana::create(['organizacion_id' => (string) $this->org->id, 'enviado_por' => (string) $this->presidente->id, 'estado' => 'en_cola', 'ejecucion' => 'prueba', 'destinatarios' => [(string) $this->estudiante->id], 'procesados' => 0]);
        $this->actingAs($this->gestor);
        $this->estado($this->cambio())->assertOk();
        $this->assertFalse(app(EntregaCampanas::class)->lote((string) $c->id, 'prueba'));
        $this->assertSame('pausada', $c->fresh()->estado);
        $this->assertSame(0, Mensaje::where('campaña_id', (string) $c->id)->count());
    }

    public function test_console_grants_and_revokes_only_registry_permission_and_requires_justification(): void
    {
        $opts = ['matricula' => '20260002', '--operador' => 'Operador local', '--motivo' => 'Designación para el registro de organizaciones.'];
        $this->artisan('comunidad:gestor-organizaciones', [...$opts, '--motivo' => ''])->assertFailed();
        $this->artisan('comunidad:gestor-organizaciones', $opts)->assertSuccessful();
        $this->assertTrue(app(AutoridadOrganizaciones::class)->permite($this->estudiante));
        $this->assertFalse(Gate::forUser($this->estudiante)->allows('update', $this->org));
        $this->artisan('comunidad:gestor-organizaciones', [...$opts, '--revocar' => true])->assertSuccessful();
        $this->actingAs($this->estudiante)->getJson('/api/gestion-organizaciones')->assertForbidden();
        $this->postJson('/api/gestion-organizaciones', $this->datos())->assertForbidden();
    }

    public function test_profile_payload_cannot_grant_permission_and_deletion_revokes_existing_grant(): void
    {
        $this->actingAs($this->estudiante)->patch('/profile', ['name' => $this->estudiante->name, 'email' => $this->estudiante->email, 'gestiona_organizaciones' => true, 'es_admin' => true])->assertSessionHasNoErrors();
        $this->assertFalse(app(AutoridadOrganizaciones::class)->permite($this->estudiante));
        $this->actingAs($this->gestor)->delete('/profile', ['password' => 'password'])->assertRedirect('/');
        $this->assertFalse(PermisoGestionOrganizaciones::where('usuario_id', (string) $this->gestor->id)->first()->activo);
    }

    public function test_demo_seeder_preserves_revocation_and_does_not_create_organizations(): void
    {
        $this->seed(GestionOrganizacionesSeeder::class);
        $u = User::where('email', 'gestion@campus.test')->firstOrFail();
        $p = PermisoGestionOrganizaciones::where('usuario_id',(string) $u->id)->firstOrFail();
        $p->update(['activo' => false]);
        $this->seed(GestionOrganizacionesSeeder::class);
        $this->assertFalse($p->fresh()->activo);
        $this->assertSame(2,Organizacion::count());
        $this->assertSame(1,User::where('email','gestion@campus.test')->count());
    }
}
