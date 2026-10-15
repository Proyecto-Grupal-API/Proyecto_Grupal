<?php

namespace Tests\Feature;

use App\Models\AuditoriaComunidad;
use App\Models\Campana;
use App\Models\ConvocatoriaBeca;
use App\Models\DelegacionOrganizacion;
use App\Models\Mensaje;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\PermisoGestionOrganizaciones;
use App\Models\RolOrganizacion;
use App\Models\SolicitudBeca;
use App\Models\User;
use App\Services\EntregaCampanas;
use App\Services\RegistroOrganizaciones;
use App\Services\ResponsabilidadesComunidad;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class DelegacionesComunidadTest extends TestCase
{
    use RefreshMongoDatabase;

    private User $presidente;

    private User $persona;

    private Organizacion $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute());
        $this->seed();
        $this->presidente = User::where('email', 'presidencia@campus.test')->firstOrFail();
        $this->persona = User::where('email', 'estudiante@campus.test')->firstOrFail();
        $this->org = Organizacion::where('slug', 'sistemas')->firstOrFail();
    }

    private function datos(array $extra = []): array
    {
        return [...['destinatario_id' => (string) $this->persona->id, 'capacidades' => ['comunicacion.gestionar'], 'inicio' => now()->timezone('America/Mexico_City')->format('Y-m-d\TH:i'), 'fin' => now()->addHour()->timezone('America/Mexico_City')->format('Y-m-d\TH:i'), 'motivo' => 'Cubrir temporalmente las tareas de comunicación.', 'clave_idempotencia' => (string) Str::uuid()], ...$extra];
    }

    private function crear(array $extra = []): string
    {
        return $this->actingAs($this->presidente)->postJson('/api/organizaciones/delegaciones', $this->datos($extra))->assertCreated()->json('delegacion.id');
    }

    public function test_delegation_is_scoped_and_retries_do_not_create_or_audit_twice(): void
    {
        $d = $this->datos();
        $id = $this->actingAs($this->presidente)->postJson('/api/organizaciones/delegaciones', $d)->assertCreated()->json('delegacion.id');
        $this->postJson('/api/organizaciones/delegaciones', $d)->assertOk()->assertJsonPath('delegacion.id', $id);
        $this->postJson('/api/organizaciones/delegaciones', [...$d, 'capacidades' => ['becas.revisar']])->assertConflict();
        $this->assertSame(1, DelegacionOrganizacion::count());
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'delegacion_otorgada')->count());
        $this->assertTrue(Gate::forUser($this->persona)->allows('comunicar', $this->org));
        $this->assertFalse(Gate::forUser($this->persona)->allows('update', $this->org));
        $this->assertFalse(Gate::forUser($this->persona)->allows('revisarBecas', $this->org));
        $this->assertFalse(Gate::forUser($this->persona)->allows('comunicar', Organizacion::where('slug', 'consejo')->firstOrFail()));
        $this->actingAs($this->persona)->postJson('/api/organizaciones/delegaciones', $this->datos())->assertForbidden();
        $this->postJson("/api/organizaciones/delegaciones/$id/revocar", ['motivo' => 'No tengo autoridad para revocarla.'])->assertForbidden();
    }

    public function test_grant_requires_current_member_supported_capabilities_and_bounded_dates(): void
    {
        $this->actingAs($this->presidente);
        foreach ([['destinatario_id' => (string) $this->presidente->id], ['destinatario_id' => (string) User::where('email', 'andrea@campus.test')->firstOrFail()->id], ['capacidades' => ['wallet.cobrar']], ['capacidades' => []], ['capacidades' => ['becas.revisar', 'becas.revisar']], ['fin' => null], ['inicio' => now()->subDay()->format('Y-m-d\TH:i')], ['fin' => now()->timezone('America/Mexico_City')->format('Y-m-d\TH:i')]] as $bad) {
            $this->postJson('/api/organizaciones/delegaciones', $this->datos($bad))->assertUnprocessable();
        }
        RolOrganizacion::where('organizacion_id', (string) $this->org->id)->where('slug_rol', 'presidencia')->update(['fecha_fin' => today()]);
        $this->postJson('/api/organizaciones/delegaciones', $this->datos(['fin' => now()->addDays(2)->timezone('America/Mexico_City')->format('Y-m-d\TH:i')]))->assertUnprocessable();
        $this->assertSame(0, DelegacionOrganizacion::count());
    }

    public function test_scheduled_grant_starts_inclusively_and_ends_exclusively(): void
    {
        $inicio = now()->addHour();
        $fin = now()->addHours(2);
        $this->crear(['inicio' => $inicio->copy()->timezone('America/Mexico_City')->format('Y-m-d\TH:i'), 'fin' => $fin->copy()->timezone('America/Mexico_City')->format('Y-m-d\TH:i')]);
        $this->assertFalse(Gate::forUser($this->persona)->allows('comunicar', $this->org));
        $this->travelTo($inicio);
        $this->assertTrue(Gate::forUser($this->persona)->allows('comunicar', $this->org));
        $this->travelTo($fin);
        $this->assertFalse(Gate::forUser($this->persona)->allows('comunicar', $this->org));
    }

    public function test_revocation_is_audited_idempotent_and_stops_queued_delivery(): void
    {
        $id = $this->crear();
        $campana = $this->actingAs($this->persona)->postJson('/api/comunicacion', ['nombre' => 'Delegación', 'asunto' => 'Aviso temporal', 'cuerpo' => 'Un mensaje pendiente de entrega para la comunidad.', 'audiencia' => 'miembros', 'accion' => 'ninguna'])->assertCreated()->json('campana.id');
        $firma = $this->postJson("/api/comunicacion/$campana/preview")->assertOk()->json('firma');
        $this->postJson("/api/comunicacion/$campana/enviar", ['firma' => $firma])->assertStatus(202);
        $this->actingAs($this->presidente)->postJson("/api/organizaciones/delegaciones/$id/revocar", ['motivo' => 'Terminó la suplencia temporal de comunicación.'])->assertOk();
        $this->postJson("/api/organizaciones/delegaciones/$id/revocar", ['motivo' => 'Reintento de la revocación anterior.'])->assertOk();
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'delegacion_revocada')->count());
        $c = Campana::findOrFail($campana);
        app(EntregaCampanas::class)->lote($campana, $c->ejecucion);
        $this->assertSame('pausada', $c->fresh()->estado);
        $this->assertSame(0, Mensaje::count());
        $this->actingAs($this->persona)->getJson('/api/comunicacion')->assertForbidden();
    }

    public function test_transfer_of_presidency_revokes_grants_and_restoring_holder_does_not_revive_them(): void
    {
        $id = $this->crear();
        $cargo = RolOrganizacion::where('organizacion_id', (string) $this->org->id)->where('slug_rol', 'presidencia')->firstOrFail();
        $base = ['slug_rol' => 'presidencia', 'fecha_inicio' => today()->toDateString(), 'fecha_fin' => null];
        $this->putJson("/api/organizaciones/roles/$cargo->id", [...$base, 'usuario_id' => (string) $this->persona->id])->assertOk();
        $this->assertNotNull(DelegacionOrganizacion::findOrFail($id)->revocada_en);
        $this->actingAs($this->persona)->putJson("/api/organizaciones/roles/$cargo->id", [...$base, 'usuario_id' => (string) $this->presidente->id])->assertOk();
        $this->assertFalse(Gate::forUser($this->persona)->allows('comunicar', $this->org));
    }

    public function test_membership_removal_revokes_grant_and_rejoining_does_not_revive_it(): void
    {
        $id = $this->crear();
        $miembro = MiembroOrganizacion::where('organizacion_id', (string) $this->org->id)->where('usuario_id', (string) $this->persona->id)->firstOrFail();
        $this->deleteJson("/api/organizaciones/miembros/$miembro->id")->assertOk();
        $this->postJson('/api/organizaciones/miembros', ['matricula' => $this->persona->matricula, 'fecha_inicio' => today()->toDateString()])->assertCreated();
        $this->assertNotNull(DelegacionOrganizacion::findOrFail($id)->revocada_en);
        $this->assertFalse(Gate::forUser($this->persona)->allows('comunicar', $this->org));
    }

    public function test_history_is_private_and_deletion_rollback_restores_the_grant(): void
    {
        $id = $this->crear();
        $this->actingAs($this->persona)->getJson('/api/organizaciones/delegaciones')->assertOk()->assertJsonCount(1, 'delegaciones')->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs(User::where('email', 'consejo@campus.test')->firstOrFail())->getJson('/api/organizaciones/delegaciones')->assertOk()->assertJsonCount(0, 'delegaciones');
        try {
            app(ResponsabilidadesComunidad::class)->eliminarCuenta($this->persona, fn () => throw new \RuntimeException('Fallo de identidad'));
            $this->fail('Debe propagarse el fallo.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fallo de identidad', $e->getMessage());
        }
        $this->assertNull(DelegacionOrganizacion::findOrFail($id)->revocada_en);
        $this->assertTrue(Gate::forUser($this->persona)->allows('comunicar', $this->org));
        app(ResponsabilidadesComunidad::class)->eliminarCuenta($this->persona, fn () => $this->persona->delete());
        $this->assertNotNull(DelegacionOrganizacion::findOrFail($id)->revocada_en);
        $this->assertFalse(Gate::forUser($this->persona)->allows('comunicar', $this->org));
    }

    public function test_suspension_revokes_delegations_permanently_without_changing_institutional_permissions(): void
    {
        $id = $this->crear();
        $actor = User::where('email', 'andrea@campus.test')->firstOrFail();
        PermisoGestionOrganizaciones::create(['usuario_id' => (string) $actor->id, 'activo' => true]);
        $registro = app(RegistroOrganizaciones::class);
        $registro->cambiarEstado($actor, (string) $this->org->id, ['estado' => 'suspendida', 'version_estado' => (int) ($this->org->version_estado ?? 0), 'clave_cambio' => (string) Str::uuid(), 'motivo' => 'Suspensión de la organización para revisión.']);
        $this->assertNotNull(DelegacionOrganizacion::findOrFail($id)->revocada_en);
        $registro->cambiarEstado($actor, (string) $this->org->id, ['estado' => 'activa', 'version_estado' => (int) $this->org->fresh()->version_estado, 'clave_cambio' => (string) Str::uuid(), 'motivo' => 'Reactivación tras concluir la revisión.']);
        $this->assertFalse(Gate::forUser($this->persona)->allows('comunicar', $this->org));
        $this->assertFalse(Gate::forUser($this->persona)->allows('gestionarRegistro', Organizacion::class));
    }

    public function test_call_management_does_not_grant_review_or_private_documents_and_foreign_presidency_cannot_revoke(): void
    {
        $id = $this->crear(['capacidades' => ['becas.gestionar']]);
        $c = ConvocatoriaBeca::create(['organizacion_id' => (string) $this->org->id, 'titulo' => 'Beca privada', 'estado' => 'borrador']);
        $s = SolicitudBeca::create(['organizacion_id' => (string) $this->org->id, 'convocatoria_id' => (string) $c->id, 'usuario_id' => (string) $this->presidente->id, 'estado' => 'pendiente', 'folio' => 'BECA-PRIVADA', 'enviado_en' => now()]);
        $this->actingAs($this->persona)->getJson('/api/becas?gestion=1')->assertOk()->assertJsonPath('puede_gestionar', true)->assertJsonPath('puede_revisar', false);
        $this->getJson("/api/becas/$c->id/solicitudes")->assertForbidden();
        $this->getJson("/api/becas/solicitudes/$s->id")->assertForbidden();
        $this->getJson("/api/becas/solicitudes/$s->id/contrato")->assertForbidden();
        $this->postJson("/api/becas/$c->id/estado", ['estado' => 'cancelada', 'motivo' => 'Cancelar la convocatoria de prueba.'])->assertOk();
        $this->actingAs(User::where('email', 'consejo@campus.test')->firstOrFail())->postJson("/api/organizaciones/delegaciones/$id/revocar", ['motivo' => 'Intento desde otra organización.'])->assertNotFound();
        $this->assertNull(DelegacionOrganizacion::findOrFail($id)->revocada_en);
    }
}
