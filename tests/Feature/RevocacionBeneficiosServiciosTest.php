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
use App\Services\RevocacionBeneficiosServicios;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class RevocacionBeneficiosServiciosTest extends TestCase
{
    use RefreshMongoDatabase;

    private const URL = 'https://revocacion-e5.test/api/v1/servicios/asignaciones';
    private const MOTIVO = 'Baja justificada del programa de beneficios.';
    private User $presidencia;
    private User $alumno;
    private Organizacion $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-11T12:00:00+00:00'));
        $this->seed();
        $this->presidencia = User::where('email', 'presidencia@campus.test')->firstOrFail();
        $this->alumno = User::where('email', 'estudiante@campus.test')->firstOrFail();
        $this->org = Organizacion::where('slug', 'sistemas')->firstOrFail();
        config(['integracion_servicios.beneficios' => [
            'url' => 'https://revocacion-e5.test', 'client_id' => 'revocacion-fixture',
            'client_secret' => 'revocacion-fixture-secret',
        ]]);
        Http::preventStrayRequests();
        Http::fake(['https://revocacion-e5.test/api/oauth/token' => Http::response([
            'access_token' => 'revocacion-fixture-token', 'expires_in' => 600,
        ])]);
    }

    public static function aliases(): array
    {
        return [['/api/v1/comunidad/portal'], ['/api']];
    }

    #[DataProvider('aliases')]
    public function test_presidencia_registra_una_intencion_persistente_antes_de_contactar_e5(string $prefix): void
    {
        $a = $this->asignada();
        $original = $a->toArray();
        $this->solicitar($a, prefix: $prefix)->assertStatus(202)->assertJsonPath('estado', 'revocacion_pendiente');
        $saved = $a->fresh();
        $this->assertSame('revocacion_pendiente', $saved->estado);
        $this->assertSame('beca:'.$a->solicitud_id.':cancelacion', $saved->revocacion['clave_idempotencia']);
        $this->assertSame((string) $this->presidencia->id, $saved->revocacion['solicitante_id']);
        $this->assertSame(self::MOTIVO, $saved->revocacion['motivo']);
        $this->assertSame(now()->toIso8601String(), $saved->revocacion['solicitado_en']);
        $this->assertSame($original['contrato'], $saved->contrato);
        $this->assertSame($original['recibo_servicios'], $saved->recibo_servicios);
        $this->assertNull($saved->recibo_cancelacion);
        $this->assertSame('aprobada', SolicitudBeca::findOrFail($a->solicitud_id)->estado);
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'beca_revocacion_solicitada')->count());
        Http::assertNothingSent();
    }

    public static function deniedProfiles(): array
    {
        return [['alumno'], ['comite_responsable'], ['comite_revisor'], ['tesoreria'],
            ['otra_org'], ['vencido'], ['baja_miembro'], ['baja_rol'], ['org_inactiva'], ['propia']];
    }

    #[DataProvider('deniedProfiles')]
    public function test_solo_presidencia_vigente_de_la_organizacion_puede_solicitar(string $profile): void
    {
        $a = $this->asignada();
        $actor = $this->presidencia;
        if ($profile === 'alumno') { $actor = $this->alumno; }
        if ($profile === 'otra_org') { $actor = User::where('email', 'consejo@campus.test')->firstOrFail(); }
        if (in_array($profile, ['comite_responsable', 'comite_revisor', 'tesoreria'], true)) {
            $actor = $this->alumno;
            SolicitudBeca::findOrFail($a->solicitud_id)->update(['usuario_id' => (string) User::where('email', 'andrea@campus.test')->firstOrFail()->id]);
            if ($profile === 'tesoreria') {
                RolOrganizacion::create(['organizacion_id' => (string) $this->org->id, 'usuario_id' => (string) $actor->id,
                    'slug_rol' => 'tesoreria', 'fecha_inicio' => now()->subDay()]);
            } else {
                ConvocatoriaBeca::findOrFail($a->convocatoria_id)->update(['comite' => [
                    'responsable_id' => $profile === 'comite_responsable' ? (string) $actor->id : 'otro',
                    'revisor_ids' => $profile === 'comite_revisor' ? [(string) $actor->id] : [], 'version' => 1,
                ]]);
            }
        }
        if (in_array($profile, ['vencido', 'baja_rol'], true)) {
            RolOrganizacion::where('usuario_id', (string) $actor->id)->where('slug_rol', 'presidencia')
                ->update($profile === 'vencido' ? ['fecha_fin' => now()->subDays(2)] : ['eliminado_en' => now()]);
        }
        if ($profile === 'baja_miembro') {
            MiembroOrganizacion::where('usuario_id', (string) $actor->id)->update(['estado' => 'inactivo']);
        }
        if ($profile === 'org_inactiva') { $this->org->update(['estado' => 'inactiva']); }
        if ($profile === 'propia') { SolicitudBeca::findOrFail($a->solicitud_id)->update(['usuario_id' => (string) $actor->id]); }

        $response = $this->solicitar($a, actor: $actor);
        $this->assertContains($response->status(), [403, 404]);
        $this->assertSame('asignada', $a->fresh()->estado);
        $this->assertNull($a->fresh()->revocacion);
        Http::assertNothingSent();
    }

    public static function invalidInput(): array
    {
        return [[['motivo' => 'corto']], [['motivo' => str_repeat('x', 1001)]],
            [['confirmacion' => false]], [['confirmacion' => null]], [['motivo' => null]]];
    }

    #[DataProvider('invalidInput')]
    public function test_exige_motivo_y_confirmacion_explicita(array $input): void
    {
        $a = $this->asignada();
        $this->solicitar($a, $input)->assertUnprocessable();
        $this->assertSame('asignada', $a->fresh()->estado);
        Http::assertNothingSent();
    }

    public static function reasons(): array { return [[10], [500], [501], [1000]]; }

    #[DataProvider('reasons')]
    public function test_preserva_motivo_completo_y_contenido_e5_estable_dentro_del_contrato(int $length): void
    {
        $a = $this->asignada();
        $reason = str_repeat('á', $length);
        $this->solicitar($a, ['motivo' => $reason])->assertStatus(202);
        $intent = $a->fresh()->revocacion;
        $this->assertSame($reason, $intent['motivo']);
        $this->assertLessThanOrEqual(500, mb_strlen($intent['cuerpo']['motivo']));
        if ($length > 500) { $this->assertStringContainsString(hash('sha256', $reason), $intent['cuerpo']['motivo']); }
        $this->solicitar($a, ['motivo' => $reason])->assertStatus(202);
        $this->assertSame($intent, $a->fresh()->revocacion);
    }

    public function test_repeticion_no_crea_otro_trabajo_y_otro_motivo_da_409(): void
    {
        $a = $this->asignada();
        $this->solicitar($a)->assertStatus(202);
        $intent = $a->fresh()->revocacion;
        $this->solicitar($a)->assertStatus(202);
        $this->solicitar($a, ['motivo' => 'Una intención distinta para el mismo beneficio.'])->assertConflict();
        $this->assertSame($intent, $a->fresh()->revocacion);
        $this->assertSame(1, AsignacionBeneficio::where('solicitud_id', $a->solicitud_id)->count());
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'beca_revocacion_solicitada')->count());
        Http::assertNothingSent();
    }

    public function test_intencion_y_auditoria_se_guardan_juntas_o_no_se_guardan(): void
    {
        $a = $this->asignada();
        AuditoriaComunidad::creating(function ($audit) {
            if ($audit->accion === 'beca_revocacion_solicitada') { throw new \RuntimeException('Fallo de auditoría controlado.'); }
        });
        try { $this->solicitar($a)->assertStatus(500); }
        finally { AuditoriaComunidad::flushEventListeners(); }
        $this->assertSame('asignada', $a->fresh()->estado);
        $this->assertNull($a->fresh()->revocacion);
        Http::assertNothingSent();
    }

    public static function outsideScope(): array
    {
        return [['pendiente_integracion', 'impresiones', 5, 0, true], ['asignada', 'BECA', 2, 10000, true],
            ['asignada', 'bono', 2, 10000, true], ['asignada', 'impresiones', 5, 1, true],
            ['asignada', 'impresiones', 5, 0, false], ['asignada', 'locker', 7, 0, true]];
    }

    #[DataProvider('outsideScope')]
    public function test_no_revoca_no_entregadas_dinero_beca_ni_sin_recibo_real(string $state, string $type, int $team, int $money, bool $receipt): void
    {
        $a = $this->asignada();
        $a->update(['estado' => $state, 'contrato' => [...$a->contrato, 'tipo' => $type,
            'equipo_destino' => $team, 'monto_centavos' => $money], 'recibo_servicios' => $receipt ? $a->recibo_servicios : null]);
        $this->solicitar($a)->assertConflict();
        $this->assertSame($state, $a->fresh()->estado);
        Http::assertNothingSent();
    }

    public function test_solicitud_y_worker_comparten_el_bloqueo_de_entrega(): void
    {
        $a = $this->asignada();
        $lock = Cache::store('file')->lock('beca-entrega:'.$a->id, 45);
        $this->assertTrue($lock->get());
        try { $this->solicitar($a)->assertConflict(); }
        finally { $lock->release(); }
        $this->solicitar($a)->assertStatus(202);
        $this->assertTrue($lock->get());
        try { app(RevocacionBeneficiosServicios::class)->procesar($a->fresh()); }
        finally { $lock->release(); }
        $this->assertSame('revocacion_pendiente', $a->fresh()->estado);
        Http::assertNothingSent();
    }

    public static function remanentes(): array { return [['impresiones', 30, 70], ['locker', 0, 1], ['locker', 1, 1]]; }

    #[DataProvider('remanentes')]
    public function test_e5_confirma_remanente_o_locker_sin_borrar_consumo_ni_historial(string $type, int $consumed, int $released): void
    {
        $a = $this->asignada($type);
        $original = $a->recibo_servicios;
        $this->solicitar($a)->assertStatus(202);
        $current = $this->conUso($original, $consumed);
        $cancel = $this->cancelada($a->fresh(), $current, $released);
        $methods = [];
        Http::fake([self::URL.'*' => function (Request $r) use ($a, $current, $cancel, &$methods) {
            $methods[] = $r->method();
            $this->assertSame('revocacion_pendiente', $a->fresh()->estado);
            if ($r->method() === 'GET') {
                $this->assertSame($a->contrato['clave_idempotencia'], $r['clave_idempotencia']);
                return $this->listing([$current]);
            }
            $this->assertSame(self::URL.'/'.rawurlencode($current['asignacion_id']).'/cancelacion', $r->url());
            $this->assertTrue($r->hasHeader('Idempotency-Key', $a->fresh()->revocacion['clave_idempotencia']));
            $this->assertSame($a->fresh()->revocacion['cuerpo'], $r->data());
            return Http::response(['data' => $cancel]);
        }]);
        app(RevocacionBeneficiosServicios::class)->procesar($a->fresh());
        $saved = $a->fresh();
        $this->assertSame('revocada', $saved->estado);
        $this->assertSame($cancel, $saved->recibo_cancelacion);
        $this->assertSame($original, $saved->recibo_servicios);
        $this->assertSame($a->contrato, $saved->contrato);
        $this->assertSame($consumed, $saved->recibo_cancelacion['asignacion']['cantidad']['consumida']);
        $this->assertSame($released, $saved->recibo_cancelacion['cantidad_liberada']);
        $this->assertSame(['GET', 'POST'], $methods);
        $this->assertNull($saved->proximo_intento_en);
        $this->assertNull($saved->error_integracion);
        $this->solicitar($a)->assertOk()->assertJsonPath('estado', 'revocada');
        app(RevocacionBeneficiosServicios::class)->procesar($saved);
        $this->assertSame(['GET', 'POST'], $methods);
    }

    public function test_respuesta_perdida_se_recupera_con_get_original_sin_otro_post(): void
    {
        $a = $this->asignada();
        $this->solicitar($a)->assertStatus(202);
        $current = $a->recibo_servicios;
        $cancel = $this->cancelada($a->fresh(), $this->conUso($current, 30), 70);
        $posts = 0;
        Http::fake([self::URL.'*' => function (Request $r) use ($a, $cancel, &$current, &$posts) {
            if ($r->method() === 'GET') {
                $this->assertSame($a->contrato['clave_idempotencia'], $r['clave_idempotencia']);
                return $this->listing([$current]);
            }
            $posts++;
            $current = $cancel['asignacion'];
            return Http::failedConnection();
        }]);
        $worker = app(RevocacionBeneficiosServicios::class);
        $worker->procesar($a->fresh());
        $this->assertPending($a);
        $worker->procesar($a->fresh()); // Respeta backoff aun invocado directamente.
        $this->travel(61)->seconds();
        $worker->procesar($a->fresh());
        $this->assertSame('revocada', $a->fresh()->estado);
        $this->assertSame($current, $a->fresh()->recibo_cancelacion);
        $this->assertSame('consulta', $a->fresh()->revocacion['origen_acuse']);
        $this->assertSame(1, $posts);
    }

    public static function ambiguousLookups(): array
    {
        return [[[], 503], [[], 200], [['data' => [], 'meta' => ['siguiente_cursor' => null]], 200],
            [['data' => []], 200], [['data' => [[], []], 'meta' => ['siguiente_cursor' => null]], 200],
            [['data' => [], 'meta' => ['siguiente_cursor' => 'otra']], 200]];
    }

    #[DataProvider('ambiguousLookups')]
    public function test_get_vacio_o_ambiguo_no_confirma_ni_cancela_ni_reentrega(array $body, int $status): void
    {
        $a = $this->asignada();
        $this->solicitar($a)->assertStatus(202);
        Http::fake([self::URL.'*' => Http::response($body, $status)]);
        app(RevocacionBeneficiosServicios::class)->procesar($a->fresh());
        $this->assertPending($a);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_starts_with($r->url(), self::URL));
    }

    public static function alteredReceipt(): array
    {
        return [['cancelacion_id', null], ['estado', 'asignado'], ['liberado', false], ['cantidad_liberada', 71],
            ['asignacion', null], ['asignacion.asignacion_id', 'otro-id'],
            ['asignacion.clave_idempotencia', 'beca:otra'], ['asignacion.beneficiario_id', 'otro-alumno'],
            ['asignacion.organizacion_id', 'otra-org'], ['asignacion.convocatoria_id', 'otra-conv'],
            ['asignacion.solicitud_id', 'otra-sol'], ['asignacion.beneficio_id', 'locker'],
            ['asignacion.folio_origen', 'BECA-OTRA'], ['asignacion.cantidad.asignada', 101],
            ['asignacion.cantidad.consumida', -1], ['asignacion.cantidad.disponible', 70],
            ['asignacion.cantidad.unidad', 'periodo'], ['asignacion.cancelacion.motivo', 'Otro motivo distinto.'],
            ['asignacion.cancelacion.referencia_origen', 'beca:otra:cancelacion'],
            ['asignacion.cancelacion.cancelada_en', null], ['asignacion.cancelacion.cancelada_en', 'tomorrow'],
            ['asignacion.cancelacion.cantidad_liberada', 69], ['asignacion.vigencia', null]];
    }

    #[DataProvider('alteredReceipt')]
    public function test_http_200_incompleto_o_recibo_alterado_no_se_acepta(string $field, mixed $value): void
    {
        $a = $this->asignada();
        $this->solicitar($a)->assertStatus(202);
        $current = $this->conUso($a->recibo_servicios, 30);
        $cancel = $this->cancelada($a->fresh(), $current, 70);
        data_set($cancel, $field, $value);
        Http::fake([self::URL.'*' => fn (Request $r) => $r->method() === 'GET'
            ? $this->listing([$current]) : Http::response(['data' => $cancel])]);
        app(RevocacionBeneficiosServicios::class)->procesar($a->fresh());
        $this->assertPending($a);
    }

    public function test_get_de_otro_recibo_no_permite_post_y_cancelacion_ajena_no_es_exito(): void
    {
        $a = $this->asignada();
        $this->solicitar($a)->assertStatus(202);
        $current = $a->recibo_servicios;
        $current['asignacion_id'] = 'otra-asignacion';
        Http::fake([self::URL.'*' => function () use (&$current) { return $this->listing([$current]); }]);
        app(RevocacionBeneficiosServicios::class)->procesar($a->fresh());
        $this->assertPending($a);
        $this->travel(61)->seconds();
        $current = $this->cancelada($a->fresh(), $a->recibo_servicios, 100)['asignacion'];
        $current['cancelacion']['referencia_origen'] = 'otra-cancelacion';
        app(RevocacionBeneficiosServicios::class)->procesar($a->fresh());
        $this->assertSame('revocacion_pendiente', $a->fresh()->estado);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_starts_with($r->url(), self::URL));
    }

    public function test_no_cancelable_conserva_revision_historial_y_no_reintenta_automaticamente(): void
    {
        $a = $this->asignada();
        $original = $a->recibo_servicios;
        $this->solicitar($a)->assertStatus(202);
        Http::fake([self::URL.'*' => fn (Request $r) => $r->method() === 'GET'
            ? $this->listing([$this->conUso($original, 100)])
            : Http::response(['codigo' => 'no_cancelable', 'mensaje' => 'Saldo consumido por completo.'], 409)]);
        app(RevocacionBeneficiosServicios::class)->procesar($a->fresh());
        $saved = $a->fresh();
        $this->assertSame('revision_requerida', $saved->estado);
        $this->assertSame('no_cancelable', $saved->revocacion['rechazo_e5']['codigo']);
        $this->assertSame($original, $saved->recibo_servicios);
        $this->assertNotNull($saved->revocacion['solicitado_en']);
        $this->assertNull($saved->recibo_cancelacion);
        $this->assertNull($saved->proximo_intento_en);
        $this->artisan('comunidad:entregar-beneficios')->assertExitCode(0);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::URL);
    }

    public function test_worker_existente_procesa_revocacion_sin_reentregar_y_respeta_backoff(): void
    {
        $a = $this->asignada();
        $this->solicitar($a)->assertStatus(202);
        $current = $a->recibo_servicios;
        $fallo = true;
        $cancel = $this->cancelada($a->fresh(), $current, 100);
        Http::fake([self::URL.'*' => function (Request $r) use (&$fallo, $current, $cancel) {
            return $fallo ? Http::response([], 503) : ($r->method() === 'GET'
                ? $this->listing([$current]) : Http::response(['data' => $cancel]));
        }]);
        $this->artisan('comunidad:entregar-beneficios')->assertExitCode(0);
        $this->assertPending($a);
        $this->artisan('comunidad:entregar-beneficios')->assertExitCode(0);
        $this->assertSame(1, $a->fresh()->revocacion['intentos']);
        $this->travel(61)->seconds();
        $fallo = false;
        $this->artisan('comunidad:entregar-beneficios')->assertExitCode(0);
        $this->assertSame('revocada', $a->fresh()->estado);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::URL);
    }

    public function test_expediente_expone_permiso_y_estados_sin_anunciar_exito_anticipado(): void
    {
        $a = $this->asignada();
        $url = '/api/v1/comunidad/portal/becas/solicitudes/'.$a->solicitud_id;
        $this->actingAs($this->presidencia)->withSession(['organizacion_id' => (string) $this->org->id])
            ->getJson($url)->assertOk()->assertJsonPath('puede_revocar_beneficio', true);
        $this->actingAs($this->alumno)->getJson($url)->assertOk()->assertJsonPath('puede_revocar_beneficio', false);
        $this->solicitar($a)->assertStatus(202);
        $this->getJson($url)->assertOk()->assertJsonPath('puede_revocar_beneficio', false)
            ->assertJsonPath('beneficio.estado', 'revocacion_pendiente')
            ->assertJsonPath('beneficio.recibo_cancelacion', null);
    }

    private function solicitar(AsignacionBeneficio $a, array $data = [], ?User $actor = null, string $prefix = '/api/v1/comunidad/portal')
    {
        return $this->actingAs($actor ?? $this->presidencia)
            ->withSession(['organizacion_id' => (string) $this->org->id])
            ->postJson($prefix.'/becas/solicitudes/'.$a->solicitud_id.'/revocacion-beneficio',
                [...['motivo' => self::MOTIVO, 'confirmacion' => true], ...$data]);
    }

    private function assertPending(AsignacionBeneficio $a): void
    {
        $saved = $a->fresh();
        $this->assertSame('revocacion_pendiente', $saved->estado);
        $this->assertNull($saved->recibo_cancelacion);
        $this->assertSame($a->recibo_servicios, $saved->recibo_servicios);
        $this->assertSame(1, $saved->revocacion['intentos']);
        $this->assertTrue($saved->proximo_intento_en->equalTo(now()->addSeconds(60)));
        $this->assertNotEmpty($saved->error_integracion);
    }

    private function listing(array $receipts): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['data' => $receipts, 'meta' => ['siguiente_cursor' => null]]);
    }

    private function conUso(array $receipt, int $consumed): array
    {
        $locker = $receipt['beneficio_id'] === 'locker';
        $receipt['cantidad']['consumida'] = $consumed;
        $receipt['cantidad']['disponible'] = $locker ? 1 : $receipt['cantidad']['asignada'] - $consumed;
        $receipt['estado'] = $locker ? ($consumed ? 'entregado' : 'asignado')
            : ($consumed === $receipt['cantidad']['asignada'] ? 'consumido' : ($consumed ? 'en_uso' : 'asignado'));
        return $receipt;
    }

    private function cancelada(AsignacionBeneficio $a, array $receipt, int $released): array
    {
        $receipt['estado'] = 'cancelado';
        $receipt['cantidad']['disponible'] = 0;
        $receipt['cancelacion'] = ['cancelacion_id' => 'CAN-E5-REAL',
            'motivo' => $a->revocacion['cuerpo']['motivo'],
            'referencia_origen' => $a->revocacion['clave_idempotencia'],
            'cancelada_en' => now()->toIso8601String(), 'liberado' => true, 'cantidad_liberada' => $released];
        return ['cancelacion_id' => 'CAN-E5-REAL', 'estado' => 'cancelado', 'liberado' => true,
            'cantidad_liberada' => $released, 'asignacion' => $receipt];
    }

    private function asignada(string $type = 'impresiones'): AsignacionBeneficio
    {
        $c = ConvocatoriaBeca::create(['organizacion_id' => (string) $this->org->id, 'titulo' => 'Beca de servicio entregada',
            'estado' => 'en_revision', 'fecha_fin' => now()->subDay(), 'vigencia_inicio' => now()->subMonth(),
            'vigencia_fin' => now()->addMonth(), 'beneficio' => ['equipo' => 5, 'slug' => $type], 'cantidad' => $type === 'locker' ? 1 : 100]);
        $s = SolicitudBeca::create(['organizacion_id' => (string) $this->org->id, 'convocatoria_id' => (string) $c->id,
            'usuario_id' => (string) $this->alumno->id, 'folio' => 'BECA-REVOCACION', 'estado' => 'aprobada',
            'enviado_en' => now()->subDays(2), 'dictamen' => ['motivo' => 'Cumple todos los requisitos.']]);
        $contract = ['version' => 1, 'equipo_destino' => 5, 'clave_idempotencia' => 'beca:'.$s->id,
            'beneficiario_id' => (string) $this->alumno->id, 'organizacion_id' => (string) $this->org->id,
            'convocatoria_id' => (string) $c->id, 'solicitud_id' => (string) $s->id, 'folio' => $s->folio,
            'tipo' => $type, 'cantidad' => $c->cantidad, 'monto_centavos' => 0, 'moneda' => 'MXN',
            'vigencia_inicio' => now()->subMonth()->toIso8601String(), 'vigencia_fin_exclusiva' => now()->addMonth()->toIso8601String()];
        $receipt = ['asignacion_id' => '6706c1d2e3f4a5b6c7d8e9f0', 'folio' => 'BEN-E5-REAL', 'beneficio_id' => $type,
            'estado' => 'asignado', 'clave_idempotencia' => $contract['clave_idempotencia'],
            'beneficiario_id' => $contract['beneficiario_id'], 'organizacion_id' => $contract['organizacion_id'],
            'convocatoria_id' => $contract['convocatoria_id'], 'solicitud_id' => $contract['solicitud_id'], 'folio_origen' => $s->folio,
            'cantidad' => ['unidad' => $type === 'locker' ? 'periodo' : 'paginas', 'asignada' => $c->cantidad,
                'consumida' => 0, 'disponible' => $c->cantidad],
            'vigencia' => ['inicio' => $contract['vigencia_inicio'], 'fin_exclusiva' => $contract['vigencia_fin_exclusiva']],
            'recurso' => $type === 'locker' ? ['tipo' => 'locker', 'codigo' => 'LKR-A-001', 'edificio' => 'A', 'zona' => 'PB'] : null,
            'evidencia' => ['asignado_en' => now()->subDay()->toIso8601String()], 'cancelacion' => null];
        return AsignacionBeneficio::create(['organizacion_id' => (string) $this->org->id,
            'convocatoria_id' => (string) $c->id, 'solicitud_id' => (string) $s->id, 'usuario_id' => (string) $this->alumno->id,
            'estado' => 'asignada', 'contrato' => $contract, 'recibo_servicios' => $receipt, 'entregado_en' => now()->subDay(), 'intentos' => 0]);
    }
}
