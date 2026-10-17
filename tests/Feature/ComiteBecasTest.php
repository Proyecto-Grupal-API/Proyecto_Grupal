<?php

namespace Tests\Feature;

use App\Models\AsignacionBeneficio;
use App\Models\AuditoriaComunidad;
use App\Models\ConvocatoriaBeca;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\RolOrganizacion;
use App\Models\SolicitudBeca;
use App\Models\User;
use App\Services\ComiteBecas;
use App\Services\ResponsabilidadesComunidad;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class ComiteBecasTest extends TestCase
{
    use RefreshMongoDatabase;

    private User $presidencia;

    private User $revisor;

    private User $responsable;

    private Organizacion $org;

    private ConvocatoriaBeca $c;

    private SolicitudBeca $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('becas');
        $this->presidencia = User::where('email', 'presidencia@campus.test')->firstOrFail();
        $this->revisor = User::where('email', 'estudiante@campus.test')->firstOrFail();
        $this->responsable = User::where('email', 'andrea@campus.test')->firstOrFail();
        $this->org = Organizacion::where('slug', 'sistemas')->firstOrFail();
        MiembroOrganizacion::firstOrCreate(['organizacion_id' => (string) $this->org->id, 'usuario_id' => (string) $this->responsable->id], ['estado' => 'activo', 'fecha_inicio' => now()->subDay()]);
        $this->c = ConvocatoriaBeca::create(['organizacion_id' => (string) $this->org->id, 'titulo' => 'Convocatoria con comité', 'estado' => 'en_revision', 'fecha_fin' => now()->subDay(), 'vigencia_fin' => now()->addMonth(), 'total_espacios' => 1, 'beneficio' => ['equipo' => 2, 'slug' => 'bono'], 'monto_centavos' => 10000, 'cantidad' => 1, 'vigencia_inicio' => now()->addDay()]);
        $this->s = SolicitudBeca::create(['convocatoria_id' => (string) $this->c->id, 'organizacion_id' => (string) $this->org->id, 'usuario_id' => (string) $this->presidencia->id, 'folio' => 'BECA-COMITE', 'estado' => 'pendiente', 'enviado_en' => now(), 'documentos' => []]);
    }

    private function configurar(array $extra = [])
    {
        return $this->actingAs($this->presidencia)->putJson("/api/becas/{$this->c->id}/comite", [...['version' => 0, 'responsable_id' => (string) $this->responsable->id, 'revisor_ids' => [(string) $this->revisor->id], 'motivo' => 'Asignación del comité de esta convocatoria.'], ...$extra]);
    }

    private function recomendar(array $extra = [])
    {
        return $this->actingAs($this->revisor)->putJson("/api/becas/solicitudes/{$this->s->id}/recomendacion", [...['version_comite' => 1, 'revision' => 0, 'recomendacion' => 'favorable', 'motivo' => 'La documentación cumple los requisitos de la convocatoria.'], ...$extra]);
    }

    public function test_only_presidency_configures_distinct_active_members_and_versions(): void
    {
        $this->configurar()->assertOk();
        $this->configurar(['version' => 0])->assertConflict();
        $this->configurar(['version' => 1, 'revisor_ids' => [(string) $this->responsable->id]])->assertUnprocessable();
        $this->actingAs($this->revisor)->putJson("/api/becas/{$this->c->id}/comite", ['version' => 1, 'responsable_id' => (string) $this->revisor->id, 'revisor_ids' => [(string) $this->responsable->id], 'motivo' => 'Intento de reasignar la resolución.'])->assertForbidden();
        $this->assertSame(1, $this->c->fresh()->comite['version']);
    }

    public function test_assigned_reviewer_can_read_only_their_call_and_never_decide_or_see_drafts(): void
    {
        $this->configurar()->assertOk();
        $this->actingAs($this->revisor)->getJson('/api/becas?gestion=1')->assertOk()->assertJsonCount(1, 'convocatorias');
        $this->getJson("/api/becas/solicitudes/{$this->s->id}")->assertOk()->assertJsonPath('puede_dictaminar', false)->assertJsonPath('comite_revision.puede_recomendar', true);
        $this->postJson("/api/becas/solicitudes/{$this->s->id}/dictamen", ['estado' => 'rechazada', 'motivo' => 'Intento de dictaminar desde revisión.'])->assertForbidden();
        $this->s->update(['estado' => 'borrador', 'enviado_en' => null]);
        $this->getJson("/api/becas/solicitudes/{$this->s->id}")->assertNotFound();
    }

    public function test_responsible_needs_recommendations_and_student_never_receives_internal_notes(): void
    {
        $this->configurar()->assertOk();
        $decision = ['estado' => 'aprobada', 'motivo' => 'Resolución final del responsable de esta convocatoria.'];
        $this->actingAs($this->responsable)->postJson("/api/becas/solicitudes/{$this->s->id}/dictamen", $decision)->assertConflict();
        $this->recomendar()->assertOk();
        $this->recomendar()->assertOk();
        $this->recomendar(['motivo' => 'Una intención distinta con revisión vieja.'])->assertConflict();
        $this->actingAs($this->responsable)->postJson("/api/becas/solicitudes/{$this->s->id}/dictamen", $decision)->assertOk();
        $this->postJson("/api/becas/solicitudes/{$this->s->id}/dictamen", $decision)->assertOk();
        $this->assertSame(1, AsignacionBeneficio::count());
        $this->assertSame(1, $this->s->fresh()->dictamen_comite['version']);
        $this->actingAs($this->presidencia)->getJson("/api/becas/solicitudes/{$this->s->id}")->assertOk()->assertJsonPath('comite_revision', null)->assertDontSee('La documentación cumple');
    }

    public function test_recommendation_audit_failure_rolls_back_and_reconfiguration_invalidates_pending_reviews(): void
    {
        $this->configurar()->assertOk();
        AuditoriaComunidad::creating(function ($a) {
            if ($a->accion === 'beca_recomendacion') {
                throw new \RuntimeException('Fallo de auditoría controlado.');
            }
        });
        try {
            $this->recomendar()->assertStatus(500);
        } finally {
            AuditoriaComunidad::flushEventListeners();
        }
        $this->actingAs($this->responsable)->getJson("/api/becas/solicitudes/{$this->s->id}")->assertJsonCount(0, 'comite_revision.recomendaciones');
        $this->recomendar()->assertOk();
        $this->configurar(['version' => 1, 'motivo' => 'Se renueva expresamente la composición del comité.'])->assertOk();
        $this->recomendar(['version_comite' => 1])->assertConflict();
        $this->actingAs($this->responsable)->getJson("/api/becas/solicitudes/{$this->s->id}")->assertJsonPath('comite_revision.puede_resolver', false);
    }

    public function test_own_applications_cannot_be_reviewed_or_decided_and_need_an_independent_reviewer(): void
    {
        $this->configurar()->assertOk();
        $this->s->update(['usuario_id' => (string) $this->revisor->id]);
        $this->recomendar()->assertForbidden();
        $this->getJson("/api/becas/solicitudes/{$this->s->id}")->assertJsonPath('comite_revision', null);
        $this->actingAs($this->responsable)->postJson("/api/becas/solicitudes/{$this->s->id}/dictamen", ['estado' => 'rechazada', 'motivo' => 'No debe resolverse sin revisión independiente.'])->assertConflict();
        $this->s->update(['usuario_id' => (string) $this->responsable->id]);
        $this->postJson("/api/becas/solicitudes/{$this->s->id}/dictamen", ['estado' => 'rechazada', 'motivo' => 'No debe resolver su propia solicitud.'])->assertUnprocessable();
    }

    public function test_baja_and_reentry_do_not_restore_committee_or_download_access(): void
    {
        $this->configurar()->assertOk();
        $uuid = (string) Str::uuid();
        $this->s->update(['documentos' => [['id' => $uuid, 'ruta' => 'prueba/privado.pdf', 'tipo' => 'application/pdf', 'nombre' => 'privado.pdf', 'tamano' => 10, 'requisito' => null]]]);
        Storage::disk('becas')->put('prueba/privado.pdf', '%PDF-1.4 privado');
        $path = "/api/becas/solicitudes/{$this->s->id}/documentos/$uuid";
        $this->actingAs($this->revisor)->get($path)->assertOk()->assertDownload();
        $member = MiembroOrganizacion::where('organizacion_id', (string) $this->org->id)->where('usuario_id', (string) $this->revisor->id)->firstOrFail();
        $this->actingAs($this->presidencia)->deleteJson('/api/organizaciones/miembros/'.$member->id)->assertOk();
        $this->postJson('/api/organizaciones/miembros', ['matricula' => $this->revisor->matricula, 'fecha_inicio' => today()->toDateString()])->assertCreated();
        $this->actingAs($this->revisor)->getJson($path)->assertForbidden();
        $this->recomendar()->assertForbidden();
        $this->assertContains((string) $this->revisor->id, $this->c->fresh()->comite['retirados_ids']);
    }

    public function test_unassigned_global_reviewer_cannot_read_a_committee_case_but_keeps_legacy_access(): void
    {
        $other = User::factory()->create();
        MiembroOrganizacion::create(['organizacion_id' => (string) $this->org->id, 'usuario_id' => (string) $other->id, 'estado' => 'activo', 'fecha_inicio' => now()->subDay()]);
        RolOrganizacion::create(['organizacion_id' => (string) $this->org->id, 'usuario_id' => (string) $other->id, 'slug_rol' => 'responsable_becas', 'fecha_inicio' => now()->subDay()]);
        $this->actingAs($other)->getJson("/api/becas/solicitudes/{$this->s->id}")->assertOk();
        $this->configurar()->assertOk();
        $this->actingAs($other)->getJson("/api/becas/solicitudes/{$this->s->id}")->assertForbidden();
        $this->getJson("/api/becas/{$this->c->id}/solicitudes")->assertForbidden();
        $this->getJson("/api/becas/{$this->c->id}/comite")->assertForbidden();
    }

    public function test_all_reviewers_must_finish_and_revisions_are_private_and_optimistic(): void
    {
        $other = User::factory()->create();
        MiembroOrganizacion::create(['organizacion_id' => (string) $this->org->id, 'usuario_id' => (string) $other->id, 'estado' => 'activo', 'fecha_inicio' => now()->subDay()]);
        $this->configurar(['revisor_ids' => [(string) $this->revisor->id, (string) $other->id]])->assertOk();
        $this->recomendar()->assertOk();
        $this->recomendar(['revision' => 1, 'recomendacion' => 'desfavorable', 'motivo' => 'Tras revisar la evidencia considero que no cumple.'])->assertOk();
        $this->recomendar(['revision' => 0])->assertConflict();
        $this->actingAs($this->responsable)->getJson("/api/becas/solicitudes/{$this->s->id}")->assertJsonPath('comite_revision.puede_resolver', false)->assertJsonPath('comite_revision.revisiones_pendientes', 1);
        $this->actingAs($other)->putJson("/api/becas/solicitudes/{$this->s->id}/recomendacion", ['version_comite' => 1, 'revision' => 0, 'recomendacion' => 'favorable', 'motivo' => 'Revisión independiente del segundo integrante.'])->assertOk();
        $this->getJson("/api/becas/solicitudes/{$this->s->id}")->assertJsonCount(0, 'comite_revision.recomendaciones')->assertDontSee('Tras revisar la evidencia');
        $this->actingAs($this->responsable)->getJson("/api/becas/solicitudes/{$this->s->id}")->assertJsonPath('comite_revision.puede_resolver', true)->assertJsonCount(2, 'comite_revision.recomendaciones');
        $this->assertSame(2, AuditoriaComunidad::where('accion', 'beca_recomendacion')->where('usuario_id', (string) $this->revisor->id)->count());
    }

    public function test_configuration_audit_failure_rolls_back_and_other_organizations_cannot_configure(): void
    {
        AuditoriaComunidad::creating(function ($a) {
            if ($a->accion === 'beca_comite_configurado') {
                throw new \RuntimeException('Fallo de configuración controlado.');
            }
        });
        try {
            $this->configurar()->assertStatus(500);
        } finally {
            AuditoriaComunidad::flushEventListeners();
        }
        $this->assertNull($this->c->fresh()->comite);
        $other = User::where('email', 'consejo@campus.test')->firstOrFail();
        $this->actingAs($other)->putJson("/api/becas/{$this->c->id}/comite", ['version' => 0, 'responsable_id' => (string) $this->responsable->id, 'revisor_ids' => [(string) $this->revisor->id], 'motivo' => 'Intento desde otra organización activa.'])->assertNotFound();
        $this->configurar(['revisor_ids' => [str_repeat('a', 24)]])->assertUnprocessable();
    }

    public function test_account_deletion_suspends_committee_permanently_until_reconfigured(): void
    {
        $this->configurar()->assertOk();
        app(ResponsabilidadesComunidad::class)->eliminarCuenta($this->revisor, fn () => $this->revisor->delete());
        $this->revisor->restore();
        MiembroOrganizacion::where('organizacion_id', (string) $this->org->id)->where('usuario_id', (string) $this->revisor->id)->update(['estado' => 'activo', 'eliminado_en' => null]);
        $this->assertNull(app(ComiteBecas::class)->funcion($this->revisor, $this->c->fresh()));
        $this->recomendar()->assertForbidden();
    }

    public function test_decision_keeps_original_review_evidence_after_a_committee_replacement(): void
    {
        $this->configurar()->assertOk();
        $this->recomendar()->assertOk();
        $this->actingAs($this->responsable)->postJson("/api/becas/solicitudes/{$this->s->id}/dictamen", ['estado' => 'rechazada', 'motivo' => 'Resolución final con revisión y evidencia conservadas.'])->assertOk();
        $this->configurar(['version' => 1, 'motivo' => 'Cambio de comité para las solicitudes todavía pendientes.'])->assertOk();
        $this->getJson("/api/becas/{$this->c->id}/comite")->assertJsonCount(2, 'historial');
        $this->actingAs($this->responsable)->getJson("/api/becas/solicitudes/{$this->s->id}")->assertJsonPath('comite_revision.version', 2)->assertJsonPath('comite_revision.version_recomendaciones', 1)->assertJsonPath('comite_revision.dictamen_utilizado.version', 1)->assertJsonCount(1, 'comite_revision.recomendaciones');
        $this->recomendar(['version_comite' => 2])->assertUnprocessable();
        $this->actingAs($this->presidencia)->getJson("/api/becas/solicitudes/{$this->s->id}")->assertDontSee('revisor_ids')->assertDontSee('La documentación cumple');
        $this->c->update(['estado' => 'finalizada']);
        $this->getJson("/api/becas/{$this->c->id}/comite")->assertJsonPath('puede_configurar', false);
    }
}
