<?php

namespace Tests\Feature;

use App\Models\Campana;
use App\Models\ConvocatoriaBeca;
use App\Models\Evento;
use App\Models\Mensaje;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\PreferenciaComunicacion;
use App\Models\RegistroEvento;
use App\Models\RolOrganizacion;
use App\Models\SolicitudBeca;
use App\Models\User;
use App\Services\AudienciaCampana;
use App\Services\EntregaCampanas;
use Database\Seeders\ComunicacionSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class ComunicacionTest extends TestCase
{
    use RefreshMongoDatabase;

    private User $admin;

    private User $alumno;

    private User $andrea;

    private Organizacion $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute());
        $this->seed();
        $this->admin = User::where('email', 'presidencia@campus.test')->firstOrFail();
        $this->alumno = User::where('email', 'estudiante@campus.test')->firstOrFail();
        $this->andrea = User::where('email', 'andrea@campus.test')->firstOrFail();
        $this->org = Organizacion::where('slug', 'sistemas')->firstOrFail();
    }

    private function datos(array $extra = []): array
    {
        return [...['nombre' => 'Campaña demo', 'asunto' => 'Aviso de la organización', 'cuerpo' => 'Este es un mensaje de prueba para la comunidad.', 'audiencia' => 'miembros', 'referencia_id' => null, 'accion' => 'eventos', 'texto_accion' => 'Explorar eventos'], ...$extra];
    }

    private function crear(array $extra = []): Campana
    {
        $id = $this->actingAs($this->admin)->postJson('/api/comunicacion', $this->datos($extra))->assertCreated()->json('campana.id');

        return Campana::findOrFail($id);
    }

    private function confirmar(Campana $c): void
    {
        $firma = $this->actingAs($this->admin)->postJson("/api/comunicacion/$c->id/preview")->assertOk()->json('firma');
        $this->postJson("/api/comunicacion/$c->id/enviar", ['firma' => $firma])->assertStatus(202);
    }

    private function entregar(Campana $c): void
    {
        $this->confirmar($c);
        $c->refresh();
        app(EntregaCampanas::class)->lote((string) $c->id, $c->ejecucion);
    }

    private function propio(Campana $c): Mensaje
    {
        return Mensaje::where('campaña_id', (string) $c->id)->where('usuario_id', (string) $this->alumno->id)->firstOrFail();
    }

    public function test_only_current_presidency_can_create_and_scope_campaigns(): void
    {
        $this->getJson('/api/comunicacion')->assertUnauthorized();
        $c = $this->crear();
        $this->actingAs($this->alumno)->postJson('/api/comunicacion', $this->datos())->assertForbidden();
        $this->getJson('/api/comunicacion')->assertForbidden();
        $this->actingAs(User::where('email', 'consejo@campus.test')->firstOrFail())->getJson('/api/comunicacion')->assertJsonCount(0, 'campanas');
        $this->postJson("/api/comunicacion/$c->id/preview")->assertNotFound();
        $this->putJson("/api/comunicacion/$c->id", $this->datos())->assertNotFound();
        $this->postJson("/api/comunicacion/$c->id/cancelar", ['motivo' => 'No es mi organización.'])->assertNotFound();
    }

    public function test_preview_and_confirm_are_bound_to_current_content_and_audience(): void
    {
        $c = $this->crear();
        $preview = $this->postJson("/api/comunicacion/$c->id/preview")->assertJsonPath('destinatarios', 2)->json();
        $this->assertArrayNotHasKey('destinatarios', $preview['campana']);
        $this->putJson("/api/comunicacion/$c->id", $this->datos(['asunto' => 'Nuevo asunto']))->assertOk();
        $this->postJson("/api/comunicacion/$c->id/enviar", ['firma' => $preview['firma']])->assertConflict();
        $firma = $this->postJson("/api/comunicacion/$c->id/preview")->json('firma');
        MiembroOrganizacion::where('organizacion_id', (string) $this->org->id)->where('usuario_id', (string) $this->alumno->id)->update(['estado' => 'inactivo']);
        $this->postJson("/api/comunicacion/$c->id/enviar", ['firma' => $firma])->assertConflict();
        $this->assertSame(0, Mensaje::count());
    }

    public function test_queue_is_persistent_and_native_worker_delivers_without_http_sending(): void
    {
        $c = $this->crear();
        $this->confirmar($c);
        $this->assertSame(0, Mensaje::count());
        $this->assertSame(1, DB::table('jobs_comunidad')->count());
        $this->artisan('queue:work', ['connection' => 'comunidad', '--queue' => 'comunidad', '--once' => true, '--sleep' => 0, '--tries' => 1])->assertExitCode(0);
        $this->assertSame(2, Mensaje::count());
        $this->assertSame('enviada', $c->fresh()->estado);
        $this->assertSame(0, DB::table('jobs_comunidad')->count());
    }

    public function test_repeated_confirmations_and_jobs_do_not_duplicate_or_reset_read_messages(): void
    {
        $c = $this->crear();
        $preview = $this->postJson("/api/comunicacion/$c->id/preview")->json();
        $this->postJson("/api/comunicacion/$c->id/enviar", ['firma' => $preview['firma']])->assertStatus(202);
        $this->postJson("/api/comunicacion/$c->id/enviar", ['firma' => $preview['firma']])->assertOk();
        $c->refresh();
        app(EntregaCampanas::class)->lote((string) $c->id, $c->ejecucion);
        $m = $this->propio($c);
        $m->update(['leido_en' => now(), 'importante' => true, 'eliminado_en' => now()]);
        // Simula caída tras insertar mensajes pero antes de guardar el cursor del lote.
        $c->update(['estado' => 'en_cola', 'procesados' => 0]);
        app(EntregaCampanas::class)->lote((string) $c->id, $c->ejecucion);
        $this->assertSame(2, Mensaje::count());
        $this->assertTrue($m->fresh()->importante);
        $this->assertNotNull($m->fresh()->eliminado_en);
        $this->getJson('/api/comunicacion')->assertJsonPath('campanas.0.total_enviados', 2)->assertJsonMissingPath('campanas.0.destinatarios');
        $this->putJson("/api/comunicacion/$c->id", $this->datos())->assertUnprocessable();
    }

    public function test_event_segment_excludes_cancelled_waitlist_and_foreign_events(): void
    {
        $e = Evento::create(['organizacion_id' => (string) $this->org->id, 'titulo' => 'Actividad']);
        foreach ([[$this->alumno, 'confirmada'], [$this->andrea, 'espera'], [$this->admin, 'cancelada']] as [$u,$estado]) {
            RegistroEvento::create(['evento_id' => (string) $e->id, 'usuario_id' => (string) $u->id, 'estado' => $estado, 'token_qr' => 'demo-'.$u->id]);
        }
        $c = $this->crear(['audiencia' => 'evento_confirmados', 'referencia_id' => (string) $e->id, 'accion' => 'boleto_evento']);
        $this->entregar($c);
        $this->assertSame(1, Mensaje::count());
        $this->assertSame('/modulo6/eventos/'.$e->id.'/boleto', $this->propio($c)->accion_url);
        $wait = $this->crear(['audiencia' => 'evento_espera', 'referencia_id' => (string) $e->id]);
        $this->assertSame([(string) $this->andrea->id], app(AudienciaCampana::class)->ids($wait));
        $e->update(['organizacion_id' => (string) Organizacion::where('slug', 'consejo')->firstOrFail()->id]);
        $this->postJson('/api/comunicacion', $this->datos(['audiencia' => 'evento_confirmados', 'referencia_id' => (string) $e->id]))->assertNotFound();
    }

    public function test_scholarship_segments_preserve_draft_privacy_and_generate_personal_links(): void
    {
        $b = ConvocatoriaBeca::create(['organizacion_id' => (string) $this->org->id, 'titulo' => 'Beca']);
        $s = SolicitudBeca::create(['organizacion_id' => (string) $this->org->id, 'convocatoria_id' => (string) $b->id, 'usuario_id' => (string) $this->alumno->id, 'estado' => 'aprobada', 'enviado_en' => now()]);
        SolicitudBeca::create(['organizacion_id' => (string) $this->org->id, 'convocatoria_id' => (string) $b->id, 'usuario_id' => (string) $this->andrea->id, 'estado' => 'borrador']);
        $c = $this->crear(['audiencia' => 'beca_aprobadas', 'referencia_id' => (string) $b->id, 'accion' => 'solicitud_beca']);
        $this->entregar($c);
        $this->assertSame(1, Mensaje::count());
        $this->assertSame('/modulo6/becas/solicitudes/'.$s->id, $this->propio($c)->accion_url);
        $all = $this->crear(['audiencia' => 'beca_solicitudes', 'referencia_id' => (string) $b->id]);
        $this->assertSame([(string) $this->alumno->id], app(AudienciaCampana::class)->ids($all));
        $s->update(['estado' => 'retirada']);
        $this->assertSame([], app(AudienciaCampana::class)->ids($all));
    }

    public function test_incompatible_actions_unrecognized_audiences_and_invalid_content_are_rejected(): void
    {
        $this->actingAs($this->admin);
        foreach ([['accion' => 'javascript:alert(1)'], ['accion' => 'solicitud_beca'], ['audiencia' => 'todos'], ['cuerpo' => 'corto'], ['nombre' => ''], ['accion' => 'eventos', 'texto_accion' => '']] as $bad) {
            $this->postJson('/api/comunicacion', $this->datos($bad))->assertUnprocessable();
        }
        $c = $this->crear(['accion_url' => 'https://evil.test']);
        $this->assertNull($c->accion_url);
    }

    public function test_muting_filters_preview_and_is_rechecked_before_delivery(): void
    {
        $c = $this->crear();
        $this->confirmar($c);
        $this->actingAs($this->alumno)->putJson('/api/bandeja/preferencias/'.$this->org->id, ['silenciada' => true])->assertOk();
        $c->refresh();
        app(EntregaCampanas::class)->lote((string) $c->id, $c->ejecucion);
        $this->assertSame(1, Mensaje::count());
        $this->assertSame(1, $c->fresh()->omitidos);
        $this->assertSame([(string) $this->admin->id], app(AudienciaCampana::class)->ids($c));
        $this->putJson('/api/bandeja/preferencias/'.$this->org->id, ['silenciada' => false, 'usuario_id' => (string) $this->admin->id])->assertOk();
        $this->assertSame(1, PreferenciaComunicacion::count());
        $this->assertSame(2, count(app(AudienciaCampana::class)->ids($c)));
    }

    public function test_new_members_are_not_added_to_frozen_audience_and_revoked_members_are_skipped(): void
    {
        $c = $this->crear();
        $this->confirmar($c);
        MiembroOrganizacion::create(['organizacion_id' => (string) $this->org->id, 'usuario_id' => (string) $this->andrea->id, 'estado' => 'activo', 'fecha_inicio' => now()]);
        MiembroOrganizacion::where('organizacion_id', (string) $this->org->id)->where('usuario_id', (string) $this->alumno->id)->update(['estado' => 'inactivo']);
        $c->refresh();
        app(EntregaCampanas::class)->lote((string) $c->id, $c->ejecucion);
        $this->assertSame(1, Mensaje::count());
        $this->assertSame((string) $this->admin->id, Mensaje::firstOrFail()->usuario_id);
    }

    public function test_empty_audience_cannot_be_sent(): void
    {
        $c = $this->crear();
        foreach ([$this->admin, $this->alumno] as $u) {
            PreferenciaComunicacion::create(['organizacion_id' => (string) $this->org->id, 'usuario_id' => (string) $u->id, 'silenciada' => true]);
        }
        $firma = $this->postJson("/api/comunicacion/$c->id/preview")->assertJsonPath('destinatarios', 0)->json('firma');
        $this->postJson("/api/comunicacion/$c->id/enviar", ['firma' => $firma])->assertUnprocessable();
    }

    public function test_revoked_sender_pauses_delivery_and_retry_uses_fresh_authorization(): void
    {
        $c = $this->crear();
        $this->confirmar($c);
        $c->refresh();
        $old = $c->ejecucion;
        $rol = RolOrganizacion::where('organizacion_id', (string) $this->org->id)->where('slug_rol', 'presidencia')->firstOrFail();
        $rol->update(['fecha_fin' => now()->subDay()]);
        app(EntregaCampanas::class)->lote((string) $c->id, $old);
        $this->assertSame('pausada', $c->fresh()->estado);
        $this->assertSame(0, Mensaje::count());
        $this->postJson("/api/comunicacion/$c->id/reintentar")->assertForbidden();
        $rol->update(['fecha_fin' => now()->addYear()]);
        $this->postJson("/api/comunicacion/$c->id/reintentar")->assertStatus(202);
        $c->refresh();
        app(EntregaCampanas::class)->lote((string) $c->id, $old);
        $this->assertSame(0, Mensaje::count());
        app(EntregaCampanas::class)->lote((string) $c->id, $c->ejecucion);
        $this->assertSame(2, Mensaje::count());
    }

    public function test_cancelled_campaign_never_delivers_and_preserves_previous_delivery(): void
    {
        $c = $this->crear();
        $this->confirmar($c);
        $c->refresh();
        Mensaje::create(['campaña_id' => (string) $c->id, 'usuario_id' => (string) $this->alumno->id, 'asunto' => 'Entrega previa', 'cuerpo' => 'Ya entregado']);
        $this->postJson("/api/comunicacion/$c->id/cancelar", ['motivo' => 'Se cancela el resto de la campaña.'])->assertOk();
        app(EntregaCampanas::class)->lote((string) $c->id, $c->ejecucion);
        $this->assertSame(1, Mensaje::count());
        $this->postJson("/api/comunicacion/$c->id/reintentar")->assertUnprocessable();
    }

    public function test_inbox_is_private_and_state_filters_work_without_changing_read_statistics(): void
    {
        $c = $this->crear();
        $this->entregar($c);
        $m = $this->propio($c);
        $this->actingAs($this->andrea)->getJson('/api/bandeja')->assertJsonCount(0, 'mensajes');
        $this->getJson('/api/bandeja/'.$m->id)->assertNotFound();
        $this->putJson('/api/bandeja/'.$m->id, ['leida' => true])->assertNotFound();
        $this->actingAs($this->alumno)->getJson('/api/bandeja/'.$m->id)->assertOk();
        $this->assertNull($m->fresh()->leido_en);
        $this->putJson('/api/bandeja/'.$m->id, ['leida' => true, 'importante' => true])->assertOk();
        $this->getJson('/api/bandeja?filtro=importantes')->assertJsonCount(1, 'mensajes');
        $this->putJson('/api/bandeja/'.$m->id, ['leida' => false])->assertOk();
        $this->getJson('/api/bandeja?filtro=no_leidos')->assertJsonCount(1, 'mensajes');
        $this->putJson('/api/bandeja/'.$m->id, ['archivada' => true])->assertOk();
        $this->getJson('/api/bandeja')->assertJsonCount(0, 'mensajes')->assertJsonPath('no_leidos', 0);
        $this->getJson('/api/bandeja?filtro=archivados')->assertJsonCount(1, 'mensajes');
        $this->putJson('/api/bandeja/'.$m->id, ['archivada' => false, 'usuario_id' => (string) $this->admin->id, 'cuerpo' => 'Hack'])->assertOk();
        $this->assertSame((string) $this->alumno->id, $m->fresh()->usuario_id);
        $this->assertNotSame('Hack', $m->fresh()->cuerpo);
        $this->actingAs($this->admin)->getJson('/api/comunicacion')->assertJsonPath('campanas.0.total_leidos', 1);
    }

    public function test_notification_count_includes_unread_messages_outside_the_five_item_preview(): void
    {
        for ($i = 0; $i < 7; $i++) {
            Mensaje::create(['usuario_id' => (string) $this->alumno->id, 'asunto' => 'Aviso '.$i, 'cuerpo' => 'Prueba', 'leido_en' => $i === 0 ? null : now()]);
        }
        $this->actingAs($this->alumno)->getJson('/api/notificaciones')->assertJsonCount(5)->assertHeader('X-Unread-Count', '1');
        $this->putJson('/api/notificaciones/leer')->assertOk();
        $this->getJson('/api/notificaciones')->assertHeader('X-Unread-Count', '0');
    }

    public function test_batches_continue_and_failed_or_duplicate_jobs_do_not_corrupt_the_cursor(): void
    {
        foreach (User::factory()->count(101)->create() as $u) {
            MiembroOrganizacion::create(['organizacion_id' => (string) $this->org->id, 'usuario_id' => (string) $u->id, 'estado' => 'activo', 'fecha_inicio' => now()]);
        }
        $c = $this->crear();
        $this->confirmar($c);
        $c->refresh();
        $this->artisan('queue:work', ['connection' => 'comunidad', '--queue' => 'comunidad', '--once' => true, '--sleep' => 0, '--tries' => 1])->assertExitCode(0);
        $this->assertSame(100, $c->fresh()->procesados);
        $this->assertSame(1, DB::table('jobs_comunidad')->count());
        app(EntregaCampanas::class)->fallo((string) $c->id, 'otra-ejecucion');
        $this->assertSame('enviando', $c->fresh()->estado);
        $this->artisan('queue:work', ['connection' => 'comunidad', '--queue' => 'comunidad', '--once' => true, '--sleep' => 0, '--tries' => 1])->assertExitCode(0);
        $this->assertSame(103, $c->fresh()->procesados);
        $this->assertSame(103, Mensaje::count());
        $this->assertSame(0, DB::table('jobs_comunidad')->count());
        $this->assertFalse(app(EntregaCampanas::class)->lote((string) $c->id, $c->ejecucion));
    }

    public function test_demo_seed_is_repeatable_and_never_dispatches_messages(): void
    {
        $this->seed(ComunicacionSeeder::class);
        $c = Campana::where('referencia_demo', 'bienvenida-sistemas')->firstOrFail();
        $c->update(['nombre' => 'Nombre ajustado']);
        $this->seed(ComunicacionSeeder::class);
        $this->assertSame(2, Campana::count());
        $this->assertSame('Nombre ajustado', $c->fresh()->nombre);
        $this->assertSame(0, Mensaje::count());
        $this->assertSame(0, DB::table('jobs_comunidad')->count());
    }
}
