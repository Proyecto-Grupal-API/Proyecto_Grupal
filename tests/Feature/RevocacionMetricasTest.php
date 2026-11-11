<?php
namespace Tests\Feature;

use App\Models\AsignacionBeneficio;
use App\Services\MetricasComunidad;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RevocacionMetricasTest extends TestCase
{
    public static function deliveredStates():array{return [['asignada'],['revocacion_pendiente'],['revocada'],['revision_requerida']];}
    #[DataProvider('deliveredStates')]
    public function test_revocation_preserves_the_historical_delivery_with_its_original_receipt(string $state):void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-11T18:00:00Z'));
        AsignacionBeneficio::create(['organizacion_id'=>'org-metric-fixture','solicitud_id'=>(string)new \MongoDB\BSON\ObjectId,
            'estado'=>$state,'contrato'=>['tipo'=>'impresiones','equipo_destino'=>5],
            'recibo_servicios'=>['asignacion_id'=>'e5-original-fixture'],'entregado_en'=>now()->subDay()]);
        $metrics=collect(app(MetricasComunidad::class)->calcular('org-metric-fixture','2026-11-01','2026-11-11',CarbonImmutable::now()))->keyBy('clave');
        $this->assertSame(1,$metrics['apoyos_entregados']['valor']);
        $this->assertSame(1,$metrics['apoyos_entregados_impresiones']['valor']);
        $this->assertStringContainsString('no beneficios activos',$metrics['apoyos_entregados']['criterio']);
    }
}
