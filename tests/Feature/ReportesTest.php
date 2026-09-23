<?php

namespace Tests\Feature;

use App\Models\AsignacionBeneficio;
use App\Models\AuditoriaComunidad;
use App\Models\Campana;
use App\Models\ConvocatoriaBeca;
use App\Models\Eleccion;
use App\Models\Encuesta;
use App\Models\Evento;
use App\Models\Mensaje;
use App\Models\Organizacion;
use App\Models\RegistroEvento;
use App\Models\ReporteTransparencia;
use App\Models\RespuestaEncuesta;
use App\Models\RolOrganizacion;
use App\Models\SolicitudBeca;
use App\Models\User;
use App\Models\Voto;
use App\Services\MetricasComunidad;
use Carbon\CarbonImmutable;
use Database\Seeders\ReporteSeeder;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class ReportesTest extends TestCase
{
    use RefreshMongoDatabase;

    private User $admin;

    private User $alumno;

    private Organizacion $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-21 18:00:00', 'UTC'));
        $this->seed();
        $this->admin = User::where('email', 'presidencia@campus.test')->firstOrFail();
        $this->alumno = User::where('email', 'estudiante@campus.test')->firstOrFail();
        $this->org = Organizacion::where('slug', 'sistemas')->firstOrFail();
    }

    private function datos(array $extra = []): array
    {
        return [...['titulo' => 'Reporte de septiembre', 'descripcion' => 'Contexto general de las actividades.', 'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-21', 'clave_solicitud' => (string) Str::uuid()], ...$extra];
    }

    private function crear(array $extra = []): ReporteTransparencia
    {
        $id = $this->actingAs($this->admin)->postJson('/api/transparencia/reportes', $this->datos($extra))->assertCreated()->json('reporte.id');

        return ReporteTransparencia::findOrFail($id);
    }

    private function valores($metricas = null): array
    {
        return collect($metricas ?? app(MetricasComunidad::class)->calcular((string) $this->org->id, '2026-09-01', '2026-09-21', CarbonImmutable::now()))->pluck('valor', 'clave')->all();
    }

    private function evento(array $extra = []): Evento
    {
        return Evento::create([...['organizacion_id' => (string) $this->org->id, 'titulo' => 'Evento de prueba', 'estado' => 'publicado', 'fecha_hora_inicio' => now()->subDay(), 'fecha_hora_fin' => now()->subHours(20), 'capacidad' => 10], ...$extra]);
    }

    private function solicitud(array $extra = []): SolicitudBeca
    {
        if (! isset($extra['convocatoria_id'])) {
            $extra['convocatoria_id'] = (string) ConvocatoriaBeca::create(['organizacion_id' => $extra['organizacion_id'] ?? (string) $this->org->id, 'titulo' => 'Otra convocatoria de prueba'])->id;
        }

        return SolicitudBeca::create([...['organizacion_id' => (string) $this->org->id, 'usuario_id' => (string) $this->alumno->id, 'estado' => 'aprobada', 'enviado_en' => now()->subDays(2), 'dictaminado_en' => now()->subDay()], ...$extra]);
    }

    public function test_reports_require_current_presidency_and_drafts_are_private_in_all_outputs(): void
    {
        $this->getJson('/api/transparencia')->assertUnauthorized();
        $r = $this->crear();
        $this->actingAs($this->alumno)->postJson('/api/transparencia/reportes', $this->datos())->assertForbidden();
        $this->getJson('/api/transparencia')->assertJsonCount(0, 'reportes');
        foreach (['', '/csv', '/imprimir'] as $suffix) {
            $this->get('/api/transparencia/reportes/'.$r->id.$suffix)->assertNotFound();
        }
        $this->postJson('/api/transparencia/reportes/'.$r->id.'/publicar', ['confirmar' => true])->assertForbidden();
        $this->actingAs(User::where('email', 'consejo@campus.test')->firstOrFail())->getJson('/api/transparencia/reportes/'.$r->id)->assertNotFound();
        $this->postJson('/api/transparencia/reportes/'.$r->id.'/retirar', ['motivo' => 'No es mi organización.'])->assertNotFound();
    }

    public function test_period_validation_and_server_owned_numbers(): void
    {
        $this->actingAs($this->admin);
        foreach ([['fecha_fin' => '2026-09-22'], ['fecha_inicio' => '2024-01-01'], ['fecha_fin' => '2026-08-31'], ['fecha_inicio' => '2026-02-30'], ['clave_solicitud' => 'x'], ['titulo' => '']] as $extra) {
            $this->postJson('/api/transparencia/reportes', $this->datos($extra))->assertUnprocessable();
        }
        $r = $this->crear(['metricas' => [['valor' => 9999]], 'estado' => 'publicado', 'organizacion_id' => 'ajena']);
        $this->assertSame('borrador', $r->estado);
        $this->assertSame((string) $this->org->id, $r->organizacion_id);
        $this->assertSame(0, array_sum($this->valores($r->metricas)));
        $this->assertIsArray($r->getRawOriginal('metricas'));
    }

    public function test_event_metrics_exclude_drafts_future_events_other_orgs_and_cancelled_reservations(): void
    {
        $e = $this->evento();
        $otro = $this->evento(['organizacion_id' => 'ajena']);
        $this->evento(['estado' => 'borrador']);
        $this->evento(['fecha_hora_inicio' => now()->addHour()]);
        $this->evento(['estado' => 'cancelado']);
        foreach ([['confirmada', 'exento', 'asistio', true], ['confirmada', 'pendiente', 'pendiente', false], ['espera', 'exento', 'pendiente', false], ['cancelada', 'exento', 'asistio', true]] as $i => [$estado,$pago,$asistencia,$asistio]) {
            RegistroEvento::create(['evento_id' => (string) $e->id, 'usuario_id' => 'persona-'.$i, 'token_qr' => (string) Str::uuid(), 'estado' => $estado, 'estado_pago' => $pago, 'estado_asistencia' => $asistencia, 'asistio_en' => $asistio ? now()->subDay() : null]);
        }
        RegistroEvento::create(['evento_id' => (string) $otro->id, 'usuario_id' => 'otra', 'token_qr' => (string) Str::uuid(), 'estado' => 'confirmada']);
        $m = $this->valores();
        $this->assertSame(1, $m['eventos_iniciados']);
        $this->assertSame(1, $m['eventos_cancelados']);
        $this->assertSame(2, $m['reservas_confirmadas']);
        $this->assertSame(1, $m['reservas_espera']);
        $this->assertSame(1, $m['reservas_pago_pendiente']);
        $this->assertSame(1, $m['asistencias']);
    }

    public function test_period_boundaries_are_mexico_dates_and_end_is_inclusive(): void
    {
        foreach (['2026-09-01T05:59:59Z', '2026-09-01T06:00:00Z', '2026-09-02T05:59:59Z', '2026-09-02T06:00:00Z'] as $t) {
            $this->evento(['fecha_hora_inicio' => CarbonImmutable::parse($t)]);
        }
        $m = $this->valores(app(MetricasComunidad::class)->calcular((string) $this->org->id, '2026-09-01', '2026-09-01', CarbonImmutable::now()));
        $this->assertSame(2, $m['eventos_iniciados']);
    }

    public function test_scholarships_use_submission_and_decision_cohorts_without_exposing_drafts_or_contracts(): void
    {
        $a = $this->solicitud();
        $b = $this->solicitud(['enviado_en' => now()->subMonths(2)]);
        $this->solicitud(['estado' => 'borrador', 'enviado_en' => null]);
        $this->solicitud(['organizacion_id' => 'ajena']);
        $this->solicitud(['estado' => 'retirada']);
        foreach ([$a, $b] as $s) {
            AsignacionBeneficio::create(['organizacion_id' => (string) $this->org->id, 'solicitud_id' => (string) $s->id, 'usuario_id' => (string) $this->alumno->id, 'estado' => 'pendiente_integracion', 'contrato' => ['moneda' => 'MXN', 'monto_centavos' => 12550, 'beneficiario_id' => (string) $this->alumno->id, 'reglas' => 'Documento privado']]);
        }
        $r = $this->crear();
        $m = $this->valores($r->metricas);
        $this->assertSame(2, $m['solicitudes_enviadas']);
        $this->assertSame(1, $m['solicitudes_aprobada']);
        $this->assertSame(1, $m['solicitudes_retirada']);
        $this->assertSame(2, $m['aprobaciones_periodo']);
        $this->assertSame(1, $m['personas_aprobadas']);
        $this->assertSame(2, $m['asignaciones_pendientes']);
        $this->assertSame(25100, $m['monto_pendiente_centavos']);
        $out = $this->getJson('/api/transparencia/reportes/'.$r->id)->assertOk()->getContent();
        foreach ([(string) $this->alumno->id, 'Documento privado', 'beneficiario_id', '"contrato":', 'motivacion'] as $privado) {
            $this->assertStringNotContainsString($privado, $out);
        }
    }

    public function test_communication_counts_delivered_and_first_read_even_when_archived_or_deleted(): void
    {
        $c = Campana::create(['organizacion_id' => (string) $this->org->id]);
        Mensaje::create(['campaña_id' => (string) $c->id, 'organizacion_id' => (string) $this->org->id, 'usuario_id' => 'privado', 'cuerpo' => 'No publicar este contenido', 'primera_lectura_en' => now(), 'leido_en' => null, 'archivado_en' => now(), 'eliminado_en' => now()]);
        Mensaje::create(['organizacion_id' => (string) $this->org->id, 'usuario_id' => 'legado']);
        $m = $this->valores();
        $this->assertSame(1, $m['mensajes_entregados']);
        $this->assertSame(1, $m['mensajes_leidos']);
    }

    public function test_only_closed_consultations_contribute_participation_counts(): void
    {
        foreach ([['publicada', now()->addDay(), null], ['publicada', now()->subDay(), null], ['cerrada', now()->addDay(), now()->subDay()], ['cancelada', now()->subDay(), null]] as [$estado,$fin,$cerrado]) {
            $e = Eleccion::create(['organizacion_id' => (string) $this->org->id, 'estado' => $estado, 'revision' => 1, 'fecha_fin' => $fin, 'cerrado_en' => $cerrado]);
            Voto::create(['organizacion_id' => (string) $this->org->id, 'consulta_id' => (string) $e->id, 'usuario_id' => 'privado', 'respuestas' => [['opcion_id' => 'secreta']]]);
        }
        $m = $this->valores();
        $this->assertSame(2, $m['votaciones_cerradas']);
        $this->assertSame(2, $m['votaciones_participaciones']);
        $this->assertSame(0, $m['encuestas_participaciones']);
    }

    public function test_snapshot_is_immutable_and_create_publish_are_idempotent(): void
    {
        $datos = $this->datos();
        $out = $this->actingAs($this->admin)->postJson('/api/transparencia/reportes', $datos)->assertCreated()->json('reporte');
        $this->evento();
        $this->postJson('/api/transparencia/reportes', $datos)->assertOk()->assertJsonPath('reporte.metricas', $out['metricas']);
        $this->postJson('/api/transparencia/reportes', [...$datos, 'titulo' => 'Distinto'])->assertConflict();
        $path = '/api/transparencia/reportes/'.$out['id'];
        $this->postJson($path.'/publicar', [])->assertUnprocessable();
        $this->postJson($path.'/publicar', ['confirmar' => true])->assertOk();
        $this->postJson($path.'/publicar', ['confirmar' => true])->assertOk();
        $this->assertSame(1, ReporteTransparencia::count());
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'reporte_publicado')->count());
        $this->actingAs($this->alumno)->getJson($path)->assertOk()->assertJsonPath('metricas', $out['metricas']);
        $this->getJson('/api/transparencia')->assertJsonCount(1, 'reportes');
        $this->putJson($path, ['metricas' => []])->assertStatus(405);
    }

    public function test_retirement_hides_all_exports_but_preserves_snapshot_and_reason(): void
    {
        $r = $this->crear();
        $path = '/api/transparencia/reportes/'.$r->id;
        $this->postJson($path.'/publicar', ['confirmar' => true])->assertOk();
        $this->postJson($path.'/retirar', ['motivo' => 'Corrección de contexto en un nuevo reporte.'])->assertOk();
        $this->postJson($path.'/publicar', ['confirmar' => true])->assertUnprocessable();
        $this->getJson($path)->assertJsonPath('estado', 'retirado');
        $this->actingAs($this->alumno)->getJson('/api/transparencia')->assertJsonCount(0, 'reportes');
        foreach (['', '/csv', '/imprimir'] as $suffix) {
            $this->get($path.$suffix)->assertNotFound();
        }
        $this->assertSame($r->metricas, $r->fresh()->metricas);
    }

    public function test_exports_are_downloadable_escape_content_and_do_not_execute_spreadsheet_formulas(): void
    {
        $r = $this->crear(['titulo' => '=1+1', 'descripcion' => '<script>alert(1)</script>']);
        $path = '/api/transparencia/reportes/'.$r->id;
        $csv = $this->get($path.'/csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringNotContainsString((string) $this->alumno->id, $csv);
        $this->get($path.'/imprimir')->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertSee('borrador');
    }

    public function test_dashboard_separates_private_applications_and_real_reservations_from_attendance(): void
    {
        $conv = ConvocatoriaBeca::create(['organizacion_id' => (string) $this->org->id, 'titulo' => 'Beca de prueba']);
        $this->solicitud(['convocatoria_id' => (string) $conv->id, 'estado' => 'pendiente']);
        $this->solicitud(['estado' => 'borrador', 'enviado_en' => null]);
        $e = $this->evento(['fecha_hora_inicio' => now()->addHour(), 'fecha_hora_fin' => now()->addHours(2)]);
        RegistroEvento::create(['evento_id' => (string) $e->id, 'usuario_id' => (string) $this->alumno->id, 'token_qr' => (string) Str::uuid(), 'estado' => 'confirmada', 'estado_asistencia' => 'pendiente']);
        $this->evento();
        $this->crear();
        $this->getJson('/api/dashboard')->assertJsonCount(1, 'ultimasBecas')->assertJsonPath('becasPendientes', 1)->assertJsonPath('eventosActivos', 1)->assertJsonPath('proximoEvento.reservas', 1)->assertJsonPath('proximoEvento.asistencias', 0)->assertJsonPath('gestion.reportes_borrador', 1)->assertJsonPath('cajaDisponible', null)->assertJsonMissingPath('ultimasBecas.0.usuario_id');
        $this->actingAs($this->alumno)->getJson('/api/dashboard')->assertJsonCount(2, 'ultimasBecas')->assertJsonPath('gestion', null)->assertJsonPath('personales.boletos', 1);
    }

    public function test_dashboard_only_counts_eligible_unanswered_consultations(): void
    {
        foreach (['open', 'done', 'notmine', 'future'] as $kind) {
            $e = Encuesta::create(['organizacion_id' => (string) $this->org->id, 'estado' => 'publicada', 'revision' => 1, 'fecha_inicio' => $kind === 'future' ? now()->addHour() : now()->subDay(), 'fecha_fin' => now()->addDay(), 'padron' => $kind === 'notmine' ? [(string) $this->admin->id] : [(string) $this->alumno->id]]);
            if ($kind === 'done') {
                RespuestaEncuesta::create(['organizacion_id' => (string) $this->org->id, 'consulta_id' => (string) $e->id, 'usuario_id' => (string) $this->alumno->id, 'respuestas' => []]);
            }
        }
        $this->actingAs($this->alumno)->getJson('/api/dashboard')->assertJsonPath('personales.consultas.encuestas', 1)->assertJsonPath('personales.consultas.votaciones', 0);
    }

    public function test_revoked_role_inactive_org_and_stale_tab_prevent_publishing(): void
    {
        $r = $this->crear();
        $path = '/api/transparencia/reportes/'.$r->id.'/publicar';
        $this->withHeader('X-Organization-Id', 'ajena')->postJson($path, ['confirmar' => true])->assertConflict();
        $this->flushHeaders();
        RolOrganizacion::where('organizacion_id', (string) $this->org->id)->update(['fecha_fin' => now()->subDay()]);
        $this->postJson($path, ['confirmar' => true])->assertForbidden();
        $this->org->update(['estado' => 'inactiva']);
        $this->getJson('/api/dashboard')->assertForbidden();
    }

    public function test_repeatable_seeder_and_search_pagination(): void
    {
        $this->seed(ReporteSeeder::class);
        $r = ReporteTransparencia::where('organizacion_id', (string) $this->org->id)->firstOrFail();
        $r->update(['titulo' => 'Personalizado']);
        $this->seed(ReporteSeeder::class);
        $this->assertSame(2, ReporteTransparencia::count());
        $this->assertSame('Personalizado', $r->fresh()->titulo);
        for ($i = 0; $i < 10; $i++) {
            ReporteTransparencia::create(['organizacion_id' => (string) $this->org->id, 'clave_solicitud' => (string) Str::uuid(), 'version' => 1, 'estado' => 'borrador', 'titulo' => 'Filtro '.$i, 'generado_en' => now()]);
        }
        $this->actingAs($this->admin)->getJson('/api/transparencia')->assertJsonCount(10, 'reportes')->assertJsonPath('last_page', 2);
        $this->getJson('/api/transparencia?buscar=Filtro')->assertJsonCount(10, 'reportes')->assertJsonPath('last_page', 1);
    }
}
