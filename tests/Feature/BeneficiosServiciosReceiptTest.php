<?php

namespace Tests\Feature;

use App\Models\AsignacionBeneficio;
use App\Services\EntregaBeneficiosServicios;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BeneficiosServiciosReceiptTest extends TestCase
{
    private const URL = 'https://beneficios-receipt.test/api/v1/servicios/asignaciones';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-10T12:00:00+00:00'));
        config(['integracion_servicios.beneficios' => [
            'url' => 'https://beneficios-receipt.test',
            'client_id' => 'receipt-fixture', 'client_secret' => 'receipt-fixture-secret',
        ]]);
        Http::preventStrayRequests();
        Http::fake(['https://beneficios-receipt.test/api/oauth/token' => Http::response([
            'access_token' => 'receipt-fixture-token', 'expires_in' => 600,
        ])]);
    }

    public static function benefits(): array
    {
        return [['impresiones'], ['locker']];
    }

    #[DataProvider('benefits')]
    public function test_beca_aprobada_acepta_el_recibo_real_sin_repetir_la_entrega(string $type): void
    {
        $award = $this->award($type);
        $contract = $award->contrato;
        $receipt = $this->receipt($type);
        $calls = [];
        Http::fake([self::URL.'*' => function (Request $request) use ($receipt, &$calls) {
            $calls[] = $request->method();
            return $request->method() === 'GET' ? $this->listing([]) : Http::response(['data' => $receipt], 201);
        }]);

        $delivery = app(EntregaBeneficiosServicios::class);
        $delivery->entregar($award);
        $saved = $award->fresh();
        $this->assertSame('asignada', $saved->estado);
        $this->assertSame($receipt, $saved->recibo_servicios);
        $this->assertTrue($saved->entregado_en->equalTo(now()));
        $this->assertSame(0, $saved->intentos);
        $this->assertNull($saved->error_integracion);
        $this->assertSame($contract, $saved->contrato);
        $delivery->entregar($saved);
        $this->assertSame(['GET', 'POST'], $calls);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::URL
            && $r->hasHeader('Idempotency-Key', 'beca:sol-recibo') && $r->data() === $contract);
        foreach (['services:benefits:read', 'services:benefits:write'] as $scope) {
            Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/oauth/token') && $r['scope'] === $scope);
        }
    }

    public static function alteredReceipts(): array
    {
        return [
            'solo id del antiguo fixture' => ['impresiones', '', ['asignacion_id' => 'e5-otra']],
            'id vacio' => ['impresiones', 'asignacion_id', ''],
            'id numerico' => ['impresiones', 'asignacion_id', 123],
            'otra clave' => ['impresiones', 'clave_idempotencia', 'beca:otra'],
            'otro beneficiario' => ['impresiones', 'beneficiario_id', 'otro-alumno'],
            'otra organizacion' => ['impresiones', 'organizacion_id', 'otra-org'],
            'otra convocatoria' => ['impresiones', 'convocatoria_id', 'otra-conv'],
            'otra solicitud' => ['impresiones', 'solicitud_id', 'otra-sol'],
            'sin solicitud' => ['impresiones', 'solicitud_id', null],
            'otro folio origen' => ['impresiones', 'folio_origen', 'BEC-OTRA'],
            'tipo de request no es beneficio_id' => ['impresiones', 'beneficio_id', null],
            'otro beneficio' => ['impresiones', 'beneficio_id', 'locker'],
            'cantidad escalar' => ['impresiones', 'cantidad', 100],
            'otra cantidad' => ['impresiones', 'cantidad.asignada', 101],
            'cantidad booleana' => ['locker', 'cantidad.asignada', true],
            'otra unidad' => ['impresiones', 'cantidad.unidad', 'periodo'],
            'consumo negativo' => ['impresiones', 'cantidad.consumida', -1],
            'saldo inconsistente' => ['impresiones', 'cantidad.disponible', 99],
            'cancelado' => ['impresiones', 'estado', 'cancelado'],
            'vencido' => ['impresiones', 'estado', 'vencido'],
            'estado desconocido' => ['impresiones', 'estado', 'active'],
            'estado de locker para impresiones' => ['impresiones', 'estado', 'entregado'],
            'estado de impresiones para locker' => ['locker', 'estado', 'en_uso'],
            'locker finalizado' => ['locker', 'estado', 'finalizado'],
            'cancelacion presente' => ['impresiones', 'cancelacion', ['cancelacion_id' => 'CAN-OTRA']],
            'recurso en impresiones' => ['impresiones', 'recurso', ['tipo' => 'locker', 'codigo' => 'LKR-A-001']],
            'sin recurso de locker' => ['locker', 'recurso', null],
            'otro recurso de locker' => ['locker', 'recurso.tipo', 'impresiones'],
            'codigo locker vacio' => ['locker', 'recurso.codigo', ''],
            'vigencia alterada de impresiones' => ['impresiones', 'vigencia.fin_exclusiva', '2026-12-21T06:00:00+00:00'],
            'vigencia ausente' => ['impresiones', 'vigencia', null],
            'vigencia relativa' => ['impresiones', 'vigencia.inicio', 'tomorrow'],
            'fecha imposible' => ['locker', 'vigencia.inicio', '2026-02-30T12:00:00+00:00'],
            'locker fuera de periodo' => ['locker', 'vigencia.inicio', '2027-02-01T00:00:00+00:00'],
        ];
    }

    #[DataProvider('alteredReceipts')]
    public function test_recibo_alterado_no_confirma_la_asignacion(string $type, string $field, mixed $value): void
    {
        $award = $this->award($type);
        $receipt = $this->receipt($type);
        if ($field === '') { $receipt = $value; } else { data_set($receipt, $field, $value); }
        Http::fake([self::URL.'*' => fn (Request $r) => $r->method() === 'GET'
            ? $this->listing([]) : Http::response(['data' => $receipt], 201)]);

        app(EntregaBeneficiosServicios::class)->entregar($award);

        $this->assertPending($award);
    }

    public function test_respuesta_perdida_se_recupera_por_clave_sin_otro_post(): void
    {
        $award = $this->award();
        $contract = $award->contrato;
        $receipt = $this->receipt();
        $stored = [];
        $posts = 0;
        Http::fake([self::URL.'*' => function (Request $r) use ($receipt, &$stored, &$posts) {
            if ($r->method() === 'GET') {
                $this->assertSame('beca:sol-recibo', $r['clave_idempotencia']);
                return $this->listing($stored);
            }
            $posts++;
            $stored = [$receipt]; // E5 confirmó internamente; solo se pierde el transporte.
            return Http::failedConnection();
        }]);

        $delivery = app(EntregaBeneficiosServicios::class);
        $delivery->entregar($award);
        $this->assertPending($award);
        $this->travel(61)->seconds();
        $delivery->entregar($award->fresh());

        $saved = $award->fresh();
        $this->assertSame('asignada', $saved->estado);
        $this->assertSame('e5-recibo-1', $saved->recibo_servicios['asignacion_id']);
        $this->assertSame($contract, $saved->contrato);
        $this->assertNull($saved->proximo_intento_en);
        $this->assertNull($saved->error_integracion);
        $this->assertSame(1, $posts);
    }

    public function test_ausencia_confirmada_permite_reintentar_con_clave_y_contenido_identicos(): void
    {
        $award = $this->award();
        $contract = $award->contrato;
        $receipt = $this->receipt();
        $posts = [];
        Http::fake([self::URL.'*' => function (Request $r) use ($receipt, &$posts) {
            if ($r->method() === 'GET') { return $this->listing([]); }
            $posts[] = ['key' => $r->header('Idempotency-Key'), 'body' => $r->data()];
            return count($posts) === 1 ? Http::failedConnection() : Http::response(['data' => $receipt], 200);
        }]);
        $delivery = app(EntregaBeneficiosServicios::class);
        $delivery->entregar($award);
        $this->assertPending($award);
        $this->travel(61)->seconds();
        $delivery->entregar($award->fresh());

        $this->assertSame('asignada', $award->fresh()->estado);
        $this->assertCount(2, $posts);
        $this->assertSame($posts[0], $posts[1]);
        $this->assertSame($contract, $posts[1]['body']);
        $this->assertSame(['beca:sol-recibo'], $posts[1]['key']);
    }

    public function test_recibo_recuperado_de_otra_operacion_no_se_acepta_ni_se_reemite(): void
    {
        $award = $this->award();
        $receipt = $this->receipt();
        $receipt['solicitud_id'] = 'otra-solicitud';
        Http::fake([self::URL.'*' => $this->listing([$receipt])]);

        app(EntregaBeneficiosServicios::class)->entregar($award);

        $this->assertPending($award);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::URL);
    }

    public static function invalidLookups(): array
    {
        return [
            'sin permiso lectura' => [[], 403],
            'fallo remoto' => [[], 503],
            'sin cuerpo' => [[], 200],
            'objeto en lugar de lista' => [['data' => ['asignacion_id' => 'otra'], 'meta' => ['siguiente_cursor' => null]], 200],
            'sin cierre de paginacion' => [['data' => []], 200],
            'pagina incompleta' => [['data' => [], 'meta' => ['siguiente_cursor' => 'otra-pagina']], 200],
            'duplicados ambiguos' => [['data' => [[], []], 'meta' => ['siguiente_cursor' => null]], 200],
        ];
    }

    #[DataProvider('invalidLookups')]
    public function test_consulta_no_concluyente_no_autoriza_otra_emision(array $body, int $status): void
    {
        $award = $this->award();
        Http::fake([self::URL.'*' => Http::response($body, $status)]);

        app(EntregaBeneficiosServicios::class)->entregar($award);

        $this->assertPending($award);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::URL);
    }

    public static function recoveredUse(): array
    {
        return [
            ['locker', 'entregado', 1, 1],
            ['impresiones', 'en_uso', 30, 70],
            ['impresiones', 'consumido', 100, 0],
        ];
    }

    #[DataProvider('recoveredUse')]
    public function test_recuperacion_admite_uso_real_sin_consumir_ni_pagar(string $type, string $state, int $used, int $remaining): void
    {
        $award = $this->award($type);
        $receipt = $this->receipt($type);
        $receipt['estado'] = $state;
        $receipt['cantidad']['consumida'] = $used;
        $receipt['cantidad']['disponible'] = $remaining;
        Http::fake([self::URL.'*' => $this->listing([$receipt])]);

        // También recupera si el proceso anterior murió antes de guardar intentos.
        app(EntregaBeneficiosServicios::class)->entregar($award);

        $this->assertSame('asignada', $award->fresh()->estado);
        $this->assertSame($state, $award->fresh()->recibo_servicios['estado']);
        Http::assertSentCount(2); // Solo OAuth de lectura y consulta.
    }

    private function assertPending(AsignacionBeneficio $award): void
    {
        $saved = $award->fresh();
        $this->assertSame('pendiente_integracion', $saved->estado);
        $this->assertNull($saved->recibo_servicios);
        $this->assertNull($saved->entregado_en);
        $this->assertSame(1, $saved->intentos);
        $this->assertTrue($saved->proximo_intento_en->equalTo(now()->addSeconds(60)));
        $this->assertNotEmpty($saved->error_integracion);
        $this->assertSame('beca:sol-recibo', $saved->contrato['clave_idempotencia']);
    }

    private function listing(array $receipts): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['data' => $receipts, 'meta' => ['siguiente_cursor' => null]]);
    }

    private function award(string $type = 'impresiones'): AsignacionBeneficio
    {
        return AsignacionBeneficio::create([
            'organizacion_id' => 'org-recibo', 'convocatoria_id' => 'conv-recibo',
            'solicitud_id' => 'sol-recibo', 'usuario_id' => 'alumno-recibo',
            'estado' => 'pendiente_integracion', 'intentos' => 0,
            'contrato' => [
                'version' => 1, 'equipo_destino' => 5, 'clave_idempotencia' => 'beca:sol-recibo',
                'beneficiario_id' => 'alumno-recibo', 'organizacion_id' => 'org-recibo',
                'convocatoria_id' => 'conv-recibo', 'solicitud_id' => 'sol-recibo',
                'folio' => 'BEC-2026-0042', 'tipo' => $type, 'cantidad' => $type === 'locker' ? 1 : 100,
                'monto_centavos' => 0, 'moneda' => 'MXN',
                'vigencia_inicio' => '2026-10-12T06:00:00+00:00',
                'vigencia_fin_exclusiva' => '2026-12-20T06:00:00+00:00', 'reglas' => 'Uso académico.',
            ],
        ]);
    }

    /** Fixture de ServiceBenefitService::present(), sin escribir recursos de E5. */
    private function receipt(string $type = 'impresiones'): array
    {
        $locker = $type === 'locker';
        return [
            'asignacion_id' => 'e5-recibo-1', 'folio' => 'BEN-20261110-K3P9Q',
            'beneficio_id' => $type, 'estado' => 'asignado', 'clave_idempotencia' => 'beca:sol-recibo',
            'beneficiario_id' => 'alumno-recibo', 'organizacion_id' => 'org-recibo',
            'convocatoria_id' => 'conv-recibo', 'solicitud_id' => 'sol-recibo', 'folio_origen' => 'BEC-2026-0042',
            'cantidad' => ['unidad' => $locker ? 'periodo' : 'paginas', 'asignada' => $locker ? 1 : 100,
                'consumida' => 0, 'disponible' => $locker ? 1 : 100],
            'vigencia' => ['inicio' => $locker ? '2026-11-10T12:00:00+00:00' : '2026-10-12T06:00:00+00:00',
                'fin_exclusiva' => $locker ? '2027-01-01T00:00:00+00:00' : '2026-12-20T06:00:00+00:00'],
            'recurso' => $locker ? ['tipo' => 'locker', 'codigo' => 'LKR-A-001', 'edificio' => 'A', 'zona' => 'PB'] : null,
            'evidencia' => $locker
                ? ['asignado_en' => '2026-11-10T12:00:00+00:00', 'folio_asignacion_locker' => 'LKR-ASG-1', 'primer_acceso_en' => null, 'liberado_en' => null]
                : ['asignado_en' => '2026-11-10T12:00:00+00:00', 'consumos' => []],
            'cancelacion' => null, 'creado_en' => '2026-11-10T12:00:00+00:00', 'actualizado_en' => '2026-11-10T12:00:00+00:00',
        ];
    }
}
