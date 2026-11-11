<?php

namespace Tests\Feature;

use App\Models\Evento;
use App\Models\PagoReservaEvento;
use App\Models\RegistroEvento;
use App\Services\MetricasComunidad;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MetricasPagosEventosTest extends TestCase
{
    private const ORG = 'organizacion-metricas-eventos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-11T18:00:00Z'));
        Http::preventStrayRequests();
    }

    public function test_cobros_y_devoluciones_con_evidencia_e2_sin_confundir_pendientes_con_dinero_devuelto(): void
    {
        $paid = $this->intento(['monto_centavos' => 2500]);
        $this->intento(['estado' => 'devolucion_pendiente', 'monto_centavos' => 5000], ['estado' => 'vencida']);
        $this->intento(['estado' => 'devuelto', 'monto_centavos' => 2000, 'comprobante_devolucion' => (string) Str::uuid()], ['estado' => 'cancelada'], ['estado' => 'cancelado']);
        $this->intento(['monto_centavos' => 2500, 'comprobante' => strtoupper($paid->comprobante)]);
        $this->intento(['estado' => 'fallido', 'monto_centavos' => 90000]);
        $this->intento(['comprobante' => null, 'monto_centavos' => 90000]);
        $this->intento(['organizacion_id' => 'otra-organizacion', 'monto_centavos' => 90000]);
        $this->intento(['creado_en' => '2026-10-31T12:00:00Z', 'monto_centavos' => 90000]);

        $metrics = $this->metricas();
        $this->assertSame(3, $metrics['eventos_cobros_confirmados']['valor']);
        $this->assertSame(9500, $metrics['eventos_cobrado_bruto_centavos']['valor']);
        $this->assertSame(1, $metrics['eventos_devoluciones_confirmadas']['valor']);
        $this->assertSame(2000, $metrics['eventos_devuelto_centavos']['valor']);
        $this->assertSame(7500, $metrics['eventos_cobrado_neto_centavos']['valor']);
        foreach (['eventos_cobrado_bruto_centavos', 'eventos_devuelto_centavos', 'eventos_cobrado_neto_centavos'] as $key) {
            $this->assertSame('centavos_mxn', $metrics[$key]['unidad']);
            $this->assertStringContainsString('cohorte de preparación', $metrics[$key]['criterio']);
            $this->assertStringContainsString('creado_en', $metrics[$key]['criterio']);
        }
        $this->assertStringContainsString('cohorte de preparación', $metrics['eventos_cobrado_bruto_centavos']['etiqueta']);
        $this->assertStringContainsString('no saldo', $metrics['eventos_cobrado_neto_centavos']['etiqueta']);
        $this->assertSame(8, PagoReservaEvento::count()); // El cálculo no concilia ni modifica intentos.
        Http::assertNothingSent();
    }

    public static function intentosExcluidos(): array
    {
        return [
            'fallido' => [['estado' => 'fallido']],
            'pendiente' => [['estado' => 'pendiente']],
            'cerrado' => [['estado' => 'cerrado']],
            'sin recibo' => [['comprobante' => null]],
            'recibo no uuid' => [['comprobante' => 'recibo-inventado']],
            'cero' => [['monto_centavos' => 0]],
            'negativo' => [['monto_centavos' => -2500]],
            'float sin truncar' => [['monto_centavos' => 2500.5]],
            'float entero no es integer' => [['monto_centavos' => 2500.0]],
            'string no es integer' => [['monto_centavos' => '2500']],
            'boolean no es integer' => [['monto_centavos' => true]],
            'otra moneda' => [[], ['moneda' => 'USD']],
            'sin moneda' => [[], ['moneda' => null]],
            'otra organizacion en intento' => [['organizacion_id' => 'otra-organizacion']],
            'evento ajeno aunque intento diga propio' => [[], [], ['organizacion_id' => 'otra-organizacion']],
            'evento distinto al de la reserva' => [['evento_id' => 'evento-inexistente']],
            'reserva inexistente' => [['registro_id' => 'reserva-inexistente']],
            'otro usuario' => [['usuario_id' => 'usuario-ajeno']],
            'preparado antes del periodo' => [['creado_en' => '2026-11-01T05:59:59Z']],
            'preparado despues del corte' => [['creado_en' => '2026-11-11T18:00:01Z']],
            'estado actualizado despues del corte' => [['actualizado_en' => '2026-11-11T18:00:01Z']],
            'confirmacion financiera futura' => [['confirmado_en' => '2026-11-11T18:00:00.001Z']],
            'confirmacion financiera fuera del periodo' => [['confirmado_en' => '2026-10-31T12:00:00.000Z']],
            'confirmacion financiera mal formada' => [['confirmado_en' => 'yesterday']],
            'confirmacion financiera imposible' => [['confirmado_en' => '2026-11-31T12:00:00.000Z']],
        ];
    }

    #[DataProvider('intentosExcluidos')]
    public function test_excluye_intentos_sin_evidencia_valida_propia_y_hasta_el_corte(array $payment, array $registration = [], array $event = []): void
    {
        $this->intento();
        $invalid = $this->intento($payment, $registration, $event);
        if (array_key_exists('monto_centavos', $payment)) {
            // El cast integer del modelo no debe convertir datos mal formados en evidencia válida.
            $this->assertSame($payment['monto_centavos'], $invalid->fresh()->getRawOriginal('monto_centavos'));
        }

        $metrics = $this->metricas();
        $this->assertSame(1, $metrics['eventos_cobros_confirmados']['valor']);
        $this->assertSame(2500, $metrics['eventos_cobrado_bruto_centavos']['valor']);
        $this->assertSame(0, $metrics['eventos_devuelto_centavos']['valor']);
        $this->assertSame(2500, $metrics['eventos_cobrado_neto_centavos']['valor']);
        Http::assertNothingSent();
    }

    public function test_estado_devuelto_sin_uuid_de_devolucion_no_reduce_el_bruto(): void
    {
        $this->intento(['estado' => 'devuelto', 'monto_centavos' => 2000]);
        $this->intento(['estado' => 'devuelto', 'monto_centavos' => 3000, 'comprobante_devolucion' => 'devolucion-inventada']);
        $this->intento(['estado' => 'devolucion_pendiente', 'monto_centavos' => 5000, 'comprobante_devolucion' => (string) Str::uuid()]);

        $metrics = $this->metricas();
        $this->assertSame(10000, $metrics['eventos_cobrado_bruto_centavos']['valor']);
        $this->assertSame(0, $metrics['eventos_devoluciones_confirmadas']['valor']);
        $this->assertSame(0, $metrics['eventos_devuelto_centavos']['valor']);
        $this->assertSame(10000, $metrics['eventos_cobrado_neto_centavos']['valor']);
    }

    public function test_deduplica_comprobantes_de_cobro_y_devolucion_sin_perder_el_estado_mas_reciente(): void
    {
        $payment = (string) Str::uuid();
        $refund = (string) Str::uuid();
        $this->intento(['comprobante' => $payment, 'estado' => 'aplicado', 'monto_centavos' => 2000, 'actualizado_en' => '2026-11-10T12:00:00Z']);
        $this->intento(['comprobante' => strtoupper($payment), 'estado' => 'devuelto', 'monto_centavos' => 2000, 'comprobante_devolucion' => $refund]);
        $this->intento(['estado' => 'devuelto', 'monto_centavos' => 2000, 'comprobante_devolucion' => strtoupper($refund)]);

        $metrics = $this->metricas();
        $this->assertSame(2, $metrics['eventos_cobros_confirmados']['valor']);
        $this->assertSame(4000, $metrics['eventos_cobrado_bruto_centavos']['valor']);
        $this->assertSame(1, $metrics['eventos_devoluciones_confirmadas']['valor']);
        $this->assertSame(2000, $metrics['eventos_devuelto_centavos']['valor']);
        $this->assertSame(2000, $metrics['eventos_cobrado_neto_centavos']['valor']);
    }

    public function test_deduplica_antes_de_elegir_periodo_para_no_contar_una_copia_antigua_del_recibo(): void
    {
        $receipt = (string) Str::uuid();
        $this->intento(['comprobante' => $receipt, 'confirmado_en' => '2026-11-04T12:00:00.000Z', 'actualizado_en' => '2026-11-10T12:00:00Z']);
        $this->intento(['comprobante' => strtoupper($receipt), 'creado_en' => '2026-10-31T12:00:00Z', 'confirmado_en' => '2026-10-31T12:00:00.000Z']);

        $this->assertSame(0, $this->metricas()['eventos_cobrado_bruto_centavos']['valor']);
    }

    public function test_usa_confirmacion_e2_cuando_existe_y_no_fecha_del_evento_ni_de_la_reserva(): void
    {
        $this->intento(['monto_centavos' => 2000, 'creado_en' => '2026-10-31T12:00:00Z', 'confirmado_en' => '2026-11-03T12:00:00.000Z']);
        $this->intento(['monto_centavos' => 90000, 'confirmado_en' => '2026-10-31T12:00:00.000Z']);
        // Un reintento histórico conserva su monto aunque la reserva ya tenga otra generación.
        $this->intento(['monto_centavos' => 2500, 'generacion' => 1], ['generacion' => 2, 'monto_centavos' => 7500]);

        $metrics = $this->metricas();
        $this->assertSame(4500, $metrics['eventos_cobrado_bruto_centavos']['valor']);
        $this->assertStringContainsString('confirmado_en', $metrics['eventos_cobrado_bruto_centavos']['criterio']);
        $this->assertSame(0, $metrics['eventos_iniciados']['valor']); // Los eventos del fixture empiezan en diciembre.
    }

    public function test_cohorte_legacy_respeta_dias_de_mexico_fin_exclusivo_y_corte_inclusivo(): void
    {
        foreach (['2026-11-01T06:00:00Z', '2026-11-06T05:59:59Z', '2026-11-11T18:00:00Z'] as $date) {
            $this->intento(['creado_en' => $date]);
        }
        $this->intento(['creado_en' => '2026-11-01T05:59:59Z']);
        $this->intento(['creado_en' => '2026-11-06T06:00:00Z']);
        $metrics = $this->metricas('2026-11-01', '2026-11-05');
        $this->assertSame(5000, $metrics['eventos_cobrado_bruto_centavos']['valor']);
        $this->assertSame(10000, $this->metricas()['eventos_cobrado_bruto_centavos']['valor']);
    }

    private function metricas(string $from = '2026-11-01', string $until = '2026-11-11'): array
    {
        return collect(app(MetricasComunidad::class)->calcular(self::ORG, $from, $until, CarbonImmutable::now()))->keyBy('clave')->all();
    }

    /** Solo persistencia E6: el intento no almacena moneda ni la fecha de devolución. */
    private function intento(array $payment = [], array $registration = [], array $event = []): PagoReservaEvento
    {
        $evento = Evento::create([
            'organizacion_id' => self::ORG, 'titulo' => 'Evento del fixture', 'estado' => 'publicado',
            'fecha_hora_inicio' => '2026-12-01T18:00:00Z', 'fecha_hora_fin' => '2026-12-01T20:00:00Z',
            'capacidad' => 10, ...$event,
        ]);
        $registro = RegistroEvento::create([
            'evento_id' => (string) $evento->id, 'usuario_id' => 'usuario-fixture', 'generacion' => 1,
            'estado' => 'confirmada', 'estado_pago' => 'pagado', 'pago_estado' => 'aplicado',
            'monto_centavos' => 2500, 'moneda' => 'MXN', 'registrado_en' => '2026-10-31T12:00:00Z',
            ...$registration,
        ]);

        return PagoReservaEvento::create([
            'registro_id' => (string) $registro->id, 'generacion' => 1, 'usuario_id' => $registro->usuario_id,
            'evento_id' => (string) $evento->id, 'organizacion_id' => self::ORG,
            'estado' => 'aplicado', 'monto_centavos' => 2500, 'comprobante' => (string) Str::uuid(),
            'cliente_id' => 'e6-fixture', 'vence_en' => '2026-11-04T12:15:00Z',
            'creado_en' => '2026-11-04T12:00:00Z', 'actualizado_en' => now(), ...$payment,
        ]);
    }
}
