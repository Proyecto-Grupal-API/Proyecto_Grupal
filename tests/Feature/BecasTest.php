<?php

namespace Tests\Feature;

use App\Models\AsignacionBeneficio;
use App\Models\AuditoriaComunidad;
use App\Models\ConvocatoriaBeca;
use App\Models\Organizacion;
use App\Models\SolicitudBeca;
use App\Models\TipoBeneficio;
use App\Models\User;
use Database\Seeders\BecaSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class BecasTest extends TestCase
{
    use RefreshMongoDatabase;

    private User $admin;

    private User $alumno;

    private User $andrea;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute());
        $this->seed();
        Storage::fake('becas');
        $this->admin = User::where('email', 'presidencia@campus.test')->firstOrFail();
        $this->alumno = User::where('email', 'estudiante@campus.test')->firstOrFail();
        $this->andrea = User::where('email', 'andrea@campus.test')->firstOrFail();
    }

    private function datos(array $extra = []): array
    {
        $dia = fn ($n) => now()->timezone('America/Mexico_City')->addDays($n)->toDateString();

        return [...['titulo' => 'Apoyo para estudiar', 'descripcion' => 'Convocatoria de prueba', 'requisitos' => 'Ser estudiante y presentar la documentación solicitada.',
            'tipo_beneficio_id' => (string) TipoBeneficio::where('slug', 'bono')->firstOrFail()->id, 'monto' => '125.50', 'cantidad' => 1, 'total_espacios' => 1,
            'fecha_inicio' => $dia(-1), 'fecha_fin' => $dia(1), 'vigencia_inicio' => $dia(2), 'vigencia_fin' => $dia(30), 'requisitos_documentos' => []], ...$extra];
    }

    private function convocatoria(array $extra = [], bool $publicar = true): ConvocatoriaBeca
    {
        $id = $this->actingAs($this->admin)->postJson('/api/becas', $this->datos($extra))->assertCreated()->json('convocatoria.id');
        if ($publicar) {
            $this->postJson("/api/becas/$id/estado", ['estado' => 'publicada'])->assertOk();
        }

        return ConvocatoriaBeca::findOrFail($id);
    }

    private function solicitar(ConvocatoriaBeca $c, ?User $u = null, bool $enviar = true): SolicitudBeca
    {
        $id = $this->actingAs($u ?? $this->alumno)->postJson("/api/becas/$c->id/solicitud")->assertOk()->json('solicitud.id');
        if ($enviar) {
            $this->putJson("/api/becas/solicitudes/$id", ['motivacion' => 'Necesito el apoyo para continuar mis estudios.', 'enviar' => true])->assertOk();
        }

        return SolicitudBeca::findOrFail($id);
    }

    private function cerrar(ConvocatoriaBeca $c): void
    {
        $this->actingAs($this->admin)->postJson("/api/becas/$c->id/estado", ['estado' => 'en_revision'])->assertOk();
    }

    private function decidir(SolicitudBeca $s, string $estado = 'aprobada')
    {
        return $this->postJson("/api/becas/solicitudes/$s->id/dictamen", ['estado' => $estado, 'motivo' => 'Revisión de los requisitos y la evidencia presentada.']);
    }

    private function subir(SolicitudBeca $s, ?int $req = 0)
    {
        return $this->postJson("/api/becas/solicitudes/$s->id/documentos", ['archivo' => UploadedFile::fake()->create('constancia.pdf', 30, 'application/pdf'), 'requisito' => $req]);
    }

    public function test_draft_publication_dates_money_and_immutable_conditions(): void
    {
        $c = $this->convocatoria(publicar: false);
        $this->assertSame(12550, $c->monto_centavos);
        $this->assertSame('00:00', $c->fecha_fin->timezone('America/Mexico_City')->format('H:i'));
        $this->assertSame(now()->timezone('America/Mexico_City')->addDays(2)->toDateString(), $c->fecha_fin->timezone('America/Mexico_City')->toDateString());
        $this->actingAs($this->andrea)->getJson('/api/becas')->assertJsonCount(0, 'convocatorias');
        $this->postJson("/api/becas/$c->id/solicitud")->assertUnprocessable();
        $this->actingAs($this->admin)->putJson("/api/becas/$c->id", $this->datos(['titulo' => 'Título corregido']))->assertOk();
        $this->postJson("/api/becas/$c->id/estado", ['estado' => 'publicada'])->assertOk();
        $this->putJson("/api/becas/$c->id", $this->datos())->assertUnprocessable();
        $this->actingAs($this->andrea)->getJson('/api/becas')->assertJsonCount(1, 'convocatorias');
        $this->solicitar($c, $this->andrea);
    }

    public function test_invalid_dates_benefits_money_and_requirements_are_rejected(): void
    {
        $this->actingAs($this->admin);
        foreach ([['monto' => '1.234'], ['monto' => '0'], ['total_espacios' => 0], ['tipo_beneficio_id' => str_repeat('a', 24)], ['vigencia_inicio' => '2000-01-01'], ['requisitos_documentos' => ['Repetido', 'Repetido']]] as $bad) {
            $this->postJson('/api/becas', $this->datos($bad))->assertUnprocessable();
        }
        $this->assertSame(0, ConvocatoriaBeca::count());
    }

    public function test_unique_application_and_submitted_fields_cannot_be_forged(): void
    {
        $c = $this->convocatoria();
        $s = $this->solicitar($c, enviar: false);
        $this->postJson("/api/becas/$c->id/solicitud", ['usuario_id' => (string) $this->andrea->id, 'estado' => 'aprobada'])->assertJsonPath('solicitud.id', (string) $s->id);
        $this->putJson("/api/becas/solicitudes/$s->id", ['motivacion' => 'Esta es una motivación válida.', 'enviar' => true, 'estado' => 'aprobada'])->assertOk();
        $this->putJson("/api/becas/solicitudes/$s->id", ['motivacion' => 'Texto cambiado por reintento.', 'enviar' => true])->assertOk();
        $this->assertSame('pendiente', $s->fresh()->estado);
        $this->assertSame('Esta es una motivación válida.', $s->fresh()->motivacion);
        $this->assertSame(1, SolicitudBeca::count());
    }

    public function test_student_cannot_manage_and_other_organization_cannot_read_or_decide(): void
    {
        $c = $this->convocatoria();
        $s = $this->solicitar($c);
        $this->postJson('/api/becas', $this->datos())->assertForbidden();
        $this->getJson('/api/becas?gestion=1')->assertForbidden();
        $this->getJson("/api/becas/$c->id/solicitudes")->assertForbidden();
        $this->decidir($s)->assertForbidden();
        $this->actingAs($this->andrea)->getJson("/api/becas/solicitudes/$s->id")->assertForbidden();
        $this->getJson('/api/becas/mis-solicitudes')->assertJsonCount(0, 'solicitudes');
        $this->putJson("/api/becas/solicitudes/$s->id", ['motivacion' => 'Intento de modificar otro usuario.', 'enviar' => false])->assertNotFound();
        $other = User::where('email', 'consejo@campus.test')->firstOrFail();
        $this->actingAs($other)->getJson("/api/becas/solicitudes/$s->id")->assertNotFound();
        $this->postJson("/api/becas/$c->id/estado", ['estado' => 'en_revision'])->assertNotFound();
        $this->decidir($s)->assertNotFound();
    }

    public function test_documents_are_private_audited_and_required_before_submission(): void
    {
        $c = $this->convocatoria(['requisitos_documentos' => ['Constancia']]);
        $s = $this->solicitar($c, enviar: false);
        $payload = ['motivacion' => 'Esta solicitud necesita una constancia.', 'enviar' => true];
        $this->putJson("/api/becas/solicitudes/$s->id", $payload)->assertUnprocessable();
        $this->subir($s, null)->assertCreated();
        // Un adjunto adicional no satisface el requisito de índice cero.
        $this->putJson("/api/becas/solicitudes/$s->id", $payload)->assertUnprocessable();
        $this->subir($s)->assertCreated();
        $doc = $s->fresh()->documentos[1];
        $this->getJson("/api/becas/solicitudes/$s->id")->assertOk()->assertDontSee($doc['ruta']);
        $this->get("/api/becas/solicitudes/$s->id/documentos/{$doc['id']}")->assertOk()->assertDownload()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->admin)->getJson("/api/becas/solicitudes/$s->id")->assertNotFound();
        $this->getJson('/api/dashboard')->assertOk()->assertJsonCount(0, 'ultimasBecas');
        $this->getJson("/api/becas/solicitudes/$s->id/documentos/{$doc['id']}")->assertNotFound();
        $this->actingAs($this->alumno)->putJson("/api/becas/solicitudes/$s->id", $payload)->assertOk();
        $this->actingAs($this->admin)->get("/api/becas/solicitudes/$s->id/documentos/{$doc['id']}")->assertOk();
        $this->assertSame(2, AuditoriaComunidad::where('accion', 'beca_documento_descargado')->count());
        $this->assertStringNotContainsString($doc['ruta'], AuditoriaComunidad::all()->toJson());
        $this->actingAs($this->andrea)->getJson("/api/becas/solicitudes/$s->id/documentos/{$doc['id']}")->assertForbidden();
    }

    public function test_upload_limits_duplicates_deletion_and_frozen_submitted_documents(): void
    {
        $c = $this->convocatoria(['requisitos_documentos' => ['Constancia']]);
        $s = $this->solicitar($c, enviar: false);
        $path = "/api/becas/solicitudes/$s->id/documentos";
        $this->postJson($path, ['archivo' => UploadedFile::fake()->create('archivo.php', 10, 'application/pdf')])->assertUnprocessable();
        $this->postJson($path, ['archivo' => UploadedFile::fake()->create('grande.pdf', 5121, 'application/pdf')])->assertUnprocessable();
        $this->subir($s, 4)->assertUnprocessable();
        $this->subir($s)->assertCreated();
        $this->subir($s)->assertUnprocessable();
        $doc = $s->fresh()->documentos[0];
        $this->deleteJson($path.'/'.$doc['id'])->assertOk();
        Storage::disk('becas')->assertMissing($doc['ruta']);
        $this->subir($s)->assertCreated();
        for ($i = 0; $i < 4; $i++) {
            $this->subir($s, null)->assertCreated();
        }$this->subir($s, null)->assertUnprocessable();
        $this->putJson("/api/becas/solicitudes/$s->id", ['motivacion' => 'Necesito el apoyo para estudiar.', 'enviar' => true])->assertOk();
        $this->subir($s, null)->assertUnprocessable();
        $doc = $s->fresh()->documentos[0];
        $this->deleteJson($path.'/'.$doc['id'])->assertUnprocessable();
    }

    public function test_approval_limits_capacity_is_idempotent_and_prepares_pending_contract(): void
    {
        $c = $this->convocatoria();
        $s = $this->solicitar($c);
        $other = $this->solicitar($c, $this->andrea);
        $this->cerrar($c);
        $this->decidir($s, 'en_revision')->assertOk();
        $this->decidir($s)->assertOk();
        $this->decidir($s)->assertOk();
        $this->decidir($other)->assertUnprocessable();
        $this->decidir($s, 'rechazada')->assertUnprocessable();
        $this->assertSame(1, AsignacionBeneficio::count());
        $a = AsignacionBeneficio::firstOrFail();
        $this->assertSame('pendiente_integracion', $a->estado);
        $this->assertSame(12550, $a->contrato['monto_centavos']);
        $this->assertSame(2, $a->contrato['equipo_destino']);
        $this->getJson("/api/becas/solicitudes/$s->id/contrato")->assertOk()->assertJsonPath('contrato.clave_idempotencia', 'beca:'.$s->id);
        $this->actingAs($this->alumno)->getJson("/api/becas/solicitudes/$s->id")->assertJsonPath('beneficio.estado', 'pendiente_integracion');
        $this->getJson("/api/becas/solicitudes/$s->id/contrato")->assertForbidden();
    }

    public function test_retry_recovers_missing_assignment_without_consuming_another_slot(): void
    {
        $c = $this->convocatoria();
        $s = $this->solicitar($c);
        $this->cerrar($c);
        $s->update(['estado' => 'aprobada', 'dictamen' => ['motivo' => 'Aprobación interrumpida antes de preparar el contrato.']]);
        $this->decidir($s)->assertOk();
        $this->decidir($s)->assertOk();
        $this->assertSame(1, AsignacionBeneficio::count());
    }

    public function test_service_assignment_targets_team_five_without_fake_allocation(): void
    {
        $tipo = TipoBeneficio::where('slug', 'locker')->firstOrFail();
        $c = $this->convocatoria(['tipo_beneficio_id' => (string) $tipo->id, 'monto' => '0']);
        $s = $this->solicitar($c);
        $this->cerrar($c);
        $this->decidir($s)->assertOk();
        $a = AsignacionBeneficio::firstOrFail();
        $this->assertSame(5, $a->contrato['equipo_destino']);
        $this->assertSame(0, $a->contrato['monto_centavos']);
        $this->assertArrayNotHasKey('locker_id', $a->contrato);
    }

    public function test_no_self_approval_or_decisions_while_reception_is_open(): void
    {
        $c = $this->convocatoria();
        $s = $this->solicitar($c);
        $self = $this->solicitar($c, $this->admin);
        $this->decidir($s)->assertUnprocessable();
        $this->cerrar($c);
        $this->decidir($self)->assertUnprocessable();
        $this->assertSame(0, AsignacionBeneficio::count());
    }

    public function test_closing_and_deadline_stop_edits_and_enable_review(): void
    {
        $c = $this->convocatoria();
        $s = $this->solicitar($c);
        $draft = $this->solicitar($c, $this->andrea, false);
        $this->travelTo($c->fecha_fin);
        $this->subir($draft, null)->assertUnprocessable();
        $this->putJson("/api/becas/solicitudes/$draft->id", ['motivacion' => 'Envío fuera del plazo permitido.', 'enviar' => true])->assertUnprocessable();
        $this->actingAs($this->admin);
        $this->decidir($s)->assertOk();
    }

    public function test_cancellation_revokes_pending_applications_but_never_exposes_drafts(): void
    {
        $c = $this->convocatoria();
        $draft = $this->solicitar($c, enviar: false);
        $submitted = $this->solicitar($c, $this->andrea);
        $this->actingAs($this->admin)->postJson("/api/becas/$c->id/estado", ['estado' => 'cancelada', 'motivo' => 'Cancelación de la convocatoria por cambios de calendario.'])->assertOk();
        $this->getJson("/api/becas/$c->id/solicitudes")->assertJsonCount(1, 'solicitudes');
        $this->getJson("/api/becas/solicitudes/$draft->id")->assertNotFound();
        $this->assertSame('cancelada', $submitted->fresh()->estado);
        $this->decidir($submitted)->assertUnprocessable();
        $this->actingAs($this->alumno)->getJson("/api/becas/solicitudes/$draft->id")->assertOk()->assertJsonPath('puede_editar', false);
    }

    public function test_withdrawal_is_final_and_does_not_publish_an_unsent_draft(): void
    {
        $c = $this->convocatoria();
        $s = $this->solicitar($c, enviar: false);
        $this->postJson("/api/becas/solicitudes/$s->id/retirar")->assertOk();
        $this->postJson("/api/becas/$c->id/solicitud")->assertOk()->assertJsonPath('solicitud.estado', 'retirada');
        $this->putJson("/api/becas/solicitudes/$s->id", ['motivacion' => 'Intento de enviar una solicitud retirada.', 'enviar' => true])->assertUnprocessable();
        $this->actingAs($this->admin)->getJson("/api/becas/$c->id/solicitudes")->assertJsonCount(0, 'solicitudes');
    }

    public function test_finalizing_requires_all_decisions_and_approved_awards_block_cancellation(): void
    {
        $c = $this->convocatoria();
        $s = $this->solicitar($c);
        $this->cerrar($c);
        $this->postJson("/api/becas/$c->id/estado", ['estado' => 'finalizada'])->assertUnprocessable();
        $this->decidir($s)->assertOk();
        $this->postJson("/api/becas/$c->id/estado", ['estado' => 'cancelada', 'motivo' => 'No debe revocar un beneficio aprobado.'])->assertUnprocessable();
        $this->postJson("/api/becas/$c->id/estado", ['estado' => 'finalizada'])->assertOk();
        $this->assertSame('finalizada', $c->fresh()->estado);
    }

    public function test_guests_inactive_organizations_and_expired_benefits_are_rejected(): void
    {
        $this->getJson('/api/becas')->assertUnauthorized();
        $c = $this->convocatoria();
        $s = $this->solicitar($c);
        $org = Organizacion::findOrFail($c->organizacion_id);
        $org->update(['estado' => 'inactiva']);
        $this->actingAs($this->andrea)->postJson("/api/becas/$c->id/solicitud")->assertUnprocessable();
        $this->getJson('/api/becas')->assertJsonCount(0, 'convocatorias');
        $org->update(['estado' => 'activa']);
        $this->travelTo($c->vigencia_fin);
        $this->actingAs($this->admin);
        $this->decidir($s)->assertUnprocessable();
    }

    public function test_demo_seed_is_repeatable_and_preserves_manual_changes(): void
    {
        $this->seed(BecaSeeder::class);
        $c = ConvocatoriaBeca::where('referencia_demo', 'beca-consejo-bono')->firstOrFail();
        $c->update(['titulo' => 'Título ajustado por el equipo']);
        $this->seed(BecaSeeder::class);
        $this->assertSame(2, ConvocatoriaBeca::count());
        $this->assertSame(6, TipoBeneficio::count());
        $this->assertSame('Título ajustado por el equipo', $c->fresh()->titulo);
    }
}
